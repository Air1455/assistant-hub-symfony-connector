<?php

namespace AssistantHub\SymfonyConnector\Service;

use AssistantHub\SymfonyConnector\Contract\LocalAuthorizationInterface;
use AssistantHub\SymfonyConnector\Contract\PairAuthenticatorInterface;
use AssistantHub\SymfonyConnector\Contract\ProposalStoreInterface;
use AssistantHub\SymfonyConnector\Protocol\Confirmation;
use AssistantHub\SymfonyConnector\Protocol\ExecutionResult;
use AssistantHub\SymfonyConnector\Protocol\Proposal;
use AssistantHub\SymfonyConnector\Protocol\ProposalExecutionReservation;
use AssistantHub\SymfonyConnector\Protocol\ProtocolException;
use AssistantHub\SymfonyConnector\Registry\CapabilityRegistry;
use Symfony\Component\HttpFoundation\Request;

final class ConnectorService
{
    public function __construct(
        private readonly CapabilityRegistry $registry,
        private readonly PairAuthenticatorInterface $pairAuthenticator,
        private readonly LocalAuthorizationInterface $localAuthorization,
        private readonly ProposalStoreInterface $proposalStore,
        private readonly int $proposalTtlSeconds,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function catalog(Request $request): array
    {
        $pair = $this->pairAuthenticator->authenticate($request);
        $catalog = [];
        foreach ($this->registry->all() as $capability) {
            try {
                $this->localAuthorization->authorize($pair, $capability->definition(), []);
                $catalog[] = $capability->definition()->toArray();
            } catch (ProtocolException $exception) {
                if ('LOCAL_POLICY_DENIED' !== $exception->protocolCode) {
                    throw $exception;
                }
            }
        }
        return $catalog;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function executeRead(string $capabilityId, array $input, Request $request): array
    {
        $pair = $this->pairAuthenticator->authenticate($request);
        $capability = $this->registry->get($capabilityId);
        $definition = $capability->definition();
        if ('read' !== $definition->kind || $definition->requiresConfirmation) {
            throw new ProtocolException('CONFIRMATION_REQUIRED', 'Write capabilities cannot use the read endpoint.', 409);
        }

        $normalized = $this->normalize($capability, $input);
        $context = $this->localAuthorization->authorize($pair, $definition, $normalized);

        return (new ExecutionResult(
            $definition->id,
            $capability->execute($normalized, $context),
            'read_'.bin2hex(random_bytes(12)),
        ))->toArray();
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function prepareProposal(string $capabilityId, array $input, Request $request): array
    {
        $pair = $this->pairAuthenticator->authenticate($request);
        $capability = $this->registry->get($capabilityId);
        $definition = $capability->definition();
        if ('write' !== $definition->kind || !$definition->requiresConfirmation) {
            throw new ProtocolException('INVALID_INPUT', 'Only declared write capabilities can prepare proposals.', 409);
        }

        $normalized = $this->normalize($capability, $input);
        $context = $this->localAuthorization->authorize($pair, $definition, $normalized);
        $proposal = Proposal::create(
            $pair->pairId,
            $definition,
            $normalized,
            $capability->preview($normalized, $context),
            $this->proposalTtlSeconds,
        );
        $this->proposalStore->save($proposal);

        return $proposal->toArray();
    }

    /** @return array<string, mixed> */
    public function executeConfirmed(string $capabilityId, Confirmation $confirmation, Request $request): array
    {
        $pair = $this->pairAuthenticator->authenticate($request);
        $capability = $this->registry->get($capabilityId);
        $definition = $capability->definition();
        if ('write' !== $definition->kind || !$definition->requiresConfirmation) {
            throw new ProtocolException('CONFIRMATION_INVALID', 'This endpoint only executes confirmed writes.', 409);
        }

        $proposal = $this->proposalStore->find($confirmation->proposalId)
            ?? throw new ProtocolException('CONFIRMATION_INVALID', 'The proposal is unknown.', 409);
        if ($proposal->isExpired()) {
            $this->proposalStore->fail($proposal->id, 'PROPOSAL_EXPIRED');
            throw new ProtocolException('PROPOSAL_EXPIRED', 'The proposal has expired.', 409);
        }
        if ($proposal->pairId !== $pair->pairId || $proposal->capabilityId !== $definition->id || $proposal->capabilityVersion !== $definition->version) {
            throw new ProtocolException('CONFIRMATION_INVALID', 'The proposal does not match this pair and capability.', 409);
        }
        if (!hash_equals($proposal->fingerprint, $confirmation->fingerprint)) {
            throw new ProtocolException('CONFIRMATION_INVALID', 'The confirmation fingerprint does not match.', 409);
        }

        $reservation = $this->proposalStore->reserve($proposal->id);
        if (ProposalExecutionReservation::COMPLETED === $reservation->status) {
            return $reservation->completedResult ?? throw new ProtocolException('EXECUTION_STATE_INVALID', 'The stored execution result is unavailable.', 500);
        }
        if (ProposalExecutionReservation::EXECUTING === $reservation->status) {
            throw new ProtocolException('EXECUTION_IN_PROGRESS', 'This proposal is already being executed.', 409, true);
        }
        if (ProposalExecutionReservation::FAILED === $reservation->status) {
            throw new ProtocolException($reservation->failureCode ?? 'EXECUTION_FAILED', 'This proposal has already failed and cannot be replayed automatically.', 409);
        }

        try {
            // Les droits sont réévalués après la réservation et juste avant l'appel officiel.
            $context = $this->localAuthorization->authorize($pair, $definition, $proposal->input)
                ->withIdempotencyKey($reservation->idempotencyKey);
            $data = $capability->execute($proposal->input, $context);
        } catch (ProtocolException $exception) {
            $this->proposalStore->fail($proposal->id, $exception->protocolCode);
            throw $exception;
        } catch (\Throwable $exception) {
            $this->proposalStore->fail($proposal->id, 'EXECUTION_FAILED');
            throw new ProtocolException('EXECUTION_FAILED', 'The confirmed execution failed and was durably blocked from automatic replay.', 500, false, previous: $exception);
        }

        $result = new ExecutionResult($definition->id, $data, $reservation->idempotencyKey);
        try {
            $this->proposalStore->complete($proposal->id, $result);
        } catch (\Throwable $exception) {
            // L'appel a pu réussir : conserver l'état executing interdit un rejeu dangereux.
            throw new ProtocolException('EXECUTION_STATE_UNCERTAIN', 'The site may have completed the operation, but the connector could not persist its result. Manual reconciliation is required.', 500, false, previous: $exception);
        }

        return $result->toArray();
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function normalize(object $capability, array $input): array
    {
        try {
            return $capability->normalizeInput($input);
        } catch (\InvalidArgumentException|\DomainException $exception) {
            throw new ProtocolException('INVALID_INPUT', $exception->getMessage(), 422);
        }
    }
}
