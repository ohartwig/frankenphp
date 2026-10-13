<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Runtime;

/**
 * Classifies HTTP requests into Frontend, Backend, or Install categories.
 *
 * Operates purely on $_SERVER — no TYPO3 dependencies.
 * Supports both path-based ("/typo3") and host-based ("https://backend.example.org")
 * backend entry point configurations.
 */
final readonly class RequestClassifier
{
    private ?string $backendHost;

    /**
     * Empty when the backend is identified by host alone.
     *
     * It used to be '/' in that case, which made the path comparison in
     * isBackendRequest() true for every request to a site root — so with
     * `https://backend.example.org` configured, the home page of every frontend
     * domain was classified as backend and lost its worker, while subpages kept
     * theirs. Silent, and only on the most-requested URL of each site.
     *
     * A host-based entry point with no path of its own therefore has no
     * path-based backend at all, and the comparison is skipped rather than
     * given a value that matches something.
     */
    private string $backendPath;

    public function __construct(string $backendEntryPoint = '/typo3')
    {
        if (str_starts_with($backendEntryPoint, 'http://') || str_starts_with($backendEntryPoint, 'https://')) {
            $parsed = parse_url($backendEntryPoint);
            $this->backendHost = $parsed['host'] ?? null;
            $this->backendPath = rtrim($parsed['path'] ?? '', '/');
        } else {
            $this->backendHost = null;
            $this->backendPath = '/' . ltrim(rtrim($backendEntryPoint, '/'), '/');
        }
    }

    /**
     * True when host-based backend detection is active and the current
     * request host matches the configured backend domain.
     */
    public function isBackendHost(): bool
    {
        return $this->backendHost !== null
            && $this->getRequestHost() === $this->backendHost;
    }

    /**
     * Backend: host matches backend domain (any path) OR path starts with backend path.
     */
    public function isBackendRequest(): bool
    {
        if ($this->isBackendHost()) {
            return true;
        }

        if ($this->backendPath === '') {
            return false;
        }

        $path = $this->getRequestPath();

        return $path === $this->backendPath
            || str_starts_with($path, $this->backendPath . '/');
    }

    /**
     * Install Tool lives at /typo3/install.php regardless of backend entry point config.
     */
    public function isInstallRequest(): bool
    {
        return str_starts_with($this->getRequestPath(), '/typo3/install.php');
    }

    /**
     * Frontend: everything that is neither backend nor install.
     */
    public function isFrontendRequest(): bool
    {
        return !$this->isBackendRequest() && !$this->isInstallRequest();
    }

    private function getRequestHost(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';

        // Strip port if present (e.g. "backend.example.org:8080")
        $colonPos = strrpos($host, ':');
        if ($colonPos !== false && !str_contains(substr($host, $colonPos), ']')) {
            $host = substr($host, 0, $colonPos);
        }

        return strtolower($host);
    }

    private function getRequestPath(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        // parse_url() yields null when the URI has no path component and false when it is
        // malformed. Neither gives us a path, so both classify as the site root — which is
        // also what the callers expect. Returning false would have raised a TypeError.
        return is_string($path) ? $path : '/';
    }
}
