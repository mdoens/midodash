<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;

class DashboardCacheService
{
    private const CACHE_TTL = 900; // 15 minutes

    private readonly string $cacheFile;

    public function __construct(
        private readonly string $projectDir,
        private readonly LoggerInterface $logger,
    ) {
        $this->cacheFile = $this->projectDir . '/var/dashboard_cache.json';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function load(): ?array
    {
        if (!file_exists($this->cacheFile)) {
            return null;
        }

        if ((time() - filemtime($this->cacheFile)) > self::CACHE_TTL) {
            $this->logger->info('Dashboard cache expired');

            return null;
        }

        $content = file_get_contents($this->cacheFile);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return null;
        }

        $this->logger->info('Dashboard loaded from cache', [
            'age_seconds' => time() - filemtime($this->cacheFile),
        ]);

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(array $data): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Remove non-serializable data (Chart objects)
        unset($data['pie_chart'], $data['radar_chart'], $data['performance_chart']);

        file_put_contents($this->cacheFile, json_encode($data));
        $this->logger->info('Dashboard cache saved');
    }

    public function invalidate(): void
    {
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }

    public function getCacheFile(): string
    {
        return $this->cacheFile;
    }
}
