<?php

namespace AssistantHub\SymfonyConnector\Controller;

use AssistantHub\SymfonyConnector\Protocol\ProtocolException;
use AssistantHub\SymfonyConnector\Service\AuthorizationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AuthorizationController
{
    private const SESSION_AUTHORIZATION = 'assistant_hub_connector.authorization';
    private const SESSION_VAULT = 'assistant_hub_connector.vault';
    private const SESSION_CSRF = 'assistant_hub_connector.csrf';

    public function __construct(private AuthorizationService $authorization, private string $connectorName)
    {
    }

    #[Route('/connector', name: 'assistant_hub_connector_home', methods: ['GET'])]
    public function home(Request $request): Response
    {
        $connected = $request->getSession()->has(self::SESSION_VAULT);
        return $this->page('Connecteur '.$this->connectorName, $connected
            ? '<p class="ok">Votre compte '.$this->escape($this->connectorName).' est connecté au connecteur.</p><p>Revenez à Assistant Hub pour utiliser les capacités autorisées.</p>'
            : '<p>Aucune connexion '.$this->escape($this->connectorName).' active dans ce navigateur.</p>');
    }

    #[Route('/connector/authorize', name: 'assistant_hub_connector_authorize', methods: ['GET'])]
    public function authorize(Request $request): Response
    {
        try {
            $authorization = $this->authorization->validateRequest($request->query->all());
        } catch (\InvalidArgumentException $exception) {
            return $this->page('Autorisation refusée', '<p class="error">'.$this->escape($exception->getMessage()).'</p>', 400);
        }
        $session = $request->getSession();
        $session->migrate(true);
        $session->set(self::SESSION_AUTHORIZATION, $authorization);
        $csrf = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $session->set(self::SESSION_CSRF, $csrf);

        return $this->loginPage($csrf, authorization: $authorization);
    }

    #[Route('/connector/login', name: 'assistant_hub_connector_login', methods: ['POST'])]
    public function login(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            return $this->page('Session expirée', '<p class="error">Rechargez la demande depuis le Hub.</p>', 400);
        }
        try {
            $vaultId = $this->authorization->login($request->request->getString('username'), $request->request->getString('password'));
            $request->getSession()->set(self::SESSION_VAULT, $vaultId);
            $request->request->set('password', '');
        } catch (ProtocolException $exception) {
            return $this->loginPage($request->getSession()->get(self::SESSION_CSRF), $exception->getMessage(), 401, $request->getSession()->get(self::SESSION_AUTHORIZATION));
        }
        $auth = $request->getSession()->get(self::SESSION_AUTHORIZATION);
        if (!is_array($auth)) {
            return $this->page('Session expirée', '<p class="error">Rechargez la demande depuis le Hub.</p>', 400);
        }
        $csrf = $request->getSession()->get(self::SESSION_CSRF);
        $content = '<div class="trust"><strong>Connexion vérifiée</strong><span>Compte authentifié par l’API officielle de '.$this->escape($this->connectorName).'</span></div>'
            .'<p>Assistant Hub demande à relier ce compte au connecteur <strong>'.$this->escape($this->connectorName).'</strong>.</p>'
            .'<ul><li>Le Hub ne recevra jamais votre mot de passe.</li><li>Les jetons restent chiffrés dans le coffre du connecteur.</li><li>Chaque demande restera contrôlée par l’API de '.$this->escape($this->connectorName).'.</li></ul>'
            .'<p class="muted">Cette autorisation doit être confirmée dans les 10 minutes.</p>'
            .'<form method="post" action="/connector/consent"><input type="hidden" name="csrf" value="'.$this->escape($csrf).'">'
            .'<button type="submit">Autoriser Assistant Hub</button></form>';

        return $this->page('Confirmer la connexion', $content);
    }

    #[Route('/connector/consent', name: 'assistant_hub_connector_consent', methods: ['POST'])]
    public function consent(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            return $this->page('Session expirée', '<p class="error">Rechargez la demande depuis le Hub.</p>', 400);
        }
        $session = $request->getSession();
        $auth = $session->get(self::SESSION_AUTHORIZATION);
        $vaultId = $session->get(self::SESSION_VAULT);
        if (!is_array($auth) || !is_string($vaultId)) {
            return $this->page('Session expirée', '<p class="error">Rechargez la demande depuis le Hub.</p>', 400);
        }
        $code = $this->authorization->authorize($auth, $vaultId);
        $separator = str_contains($auth['redirect_uri'], '?') ? '&' : '?';
        $location = $auth['redirect_uri'].$separator.http_build_query(['code' => $code, 'state' => $auth['state']], '', '&', PHP_QUERY_RFC3986);
        $session->remove(self::SESSION_AUTHORIZATION);
        $session->remove(self::SESSION_CSRF);

        return new RedirectResponse($location, 302, ['Cache-Control' => 'no-store']);
    }

    #[Route('/assistant-hub/pairing/token', name: 'assistant_hub_connector_pairing_token', methods: ['POST'])]
    public function token(Request $request): JsonResponse
    {
        try {
            $payload = $request->toArray();
            $pair = $this->authorization->exchange($payload);
            return new JsonResponse(['pairing' => $pair], 201, ['Cache-Control' => 'no-store']);
        } catch (\InvalidArgumentException|\DomainException $exception) {
            return new JsonResponse(['error' => ['code' => 'AUTHORIZATION_CODE_INVALID', 'message' => $exception->getMessage(), 'retryable' => false]], 400, ['Cache-Control' => 'no-store']);
        } catch (\Throwable) {
            return new JsonResponse(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'The connector failed safely.', 'retryable' => false]], 500, ['Cache-Control' => 'no-store']);
        }
    }

    private function loginPage(string $csrf, ?string $error = null, int $status = 200, ?array $authorization = null): Response
    {
        $message = null === $error ? '' : '<p class="error">'.$this->escape($error).'</p>';
        $hubOrigin = is_array($authorization) && is_string($authorization['redirect_uri'] ?? null)
            ? (string) parse_url($authorization['redirect_uri'], PHP_URL_HOST)
            : 'Assistant Hub';
        $content = '<p class="origin">Espace sécurisé '.$this->escape($this->connectorName).'</p>'
            .$message.'<p>Connectez-vous avec votre compte <strong>'.$this->escape($this->connectorName).'</strong>. Les identifiants sont transmis directement à son API officielle et ne sont jamais envoyés au Hub.</p>'
            .'<p class="muted">Demande initiée par '.$this->escape($hubOrigin).'.</p>'
            .'<form method="post" action="/connector/login" autocomplete="on"><input type="hidden" name="csrf" value="'.$this->escape($csrf).'">'
            .'<label>Adresse e-mail '.$this->escape($this->connectorName).'<input name="username" type="email" required autocomplete="username" autofocus></label>'
            .'<label>Mot de passe<input name="password" type="password" required autocomplete="current-password"></label>'
            .'<button type="submit">Se connecter</button></form>';
        return $this->page('Connexion à '.$this->connectorName, $content, $status);
    }

    private function validCsrf(Request $request): bool
    {
        $expected = $request->getSession()->get(self::SESSION_CSRF);
        return is_string($expected) && hash_equals($expected, $request->request->getString('csrf'));
    }

    private function page(string $title, string $content, int $status = 200): Response
    {
        $html = '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>'.$this->escape($title).'</title><style>:root{font-family:Inter,ui-sans-serif,system-ui;color:#132a26;background:#eef3f1}*{box-sizing:border-box}body{margin:0;padding:24px;background:radial-gradient(circle at top right,#d7eee7,#eef3f1 48%)}main{max-width:38rem;margin:7vh auto;background:#fff;padding:clamp(24px,6vw,42px);border:1px solid #d9e2de;border-radius:22px;box-shadow:0 20px 55px #193b3020}h1{margin:.25rem 0 1rem;font-size:clamp(2rem,7vw,3rem);letter-spacing:-.045em}p{line-height:1.6}.origin{display:inline-flex;margin:0;padding:6px 10px;border-radius:999px;background:#d9f1e6;color:#0b5c48;font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em}.muted{color:#60706b;font-size:.9rem}label{display:grid;gap:.45rem;margin:1.1rem 0;font-weight:750}input{padding:.9rem;border:1px solid #aeb8b3;border-radius:10px;font:inherit}input:focus{outline:3px solid #146b5830;border-color:#146b58}button{width:100%;padding:.9rem 1rem;border:0;border-radius:10px;background:#146b58;color:white;font:inherit;font-weight:800;cursor:pointer}.error{padding:12px;border-radius:10px;background:#fae3df;color:#85342d}.ok{color:#087747}.trust{display:grid;gap:3px;padding:14px;border-radius:12px;background:#e6f5ef}.trust span{color:#4f6860;font-size:.88rem}li{margin:.5rem 0}</style>'
            .'<main><h1>'.$this->escape($title).'</h1>'.$content.'</main></html>';
        return new Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store', 'X-Frame-Options' => 'DENY', 'Referrer-Policy' => 'no-referrer']);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
