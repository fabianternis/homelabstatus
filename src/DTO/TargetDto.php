<?php

declare(strict_types=1);

namespace App\DTO;

readonly class TargetDto
{
    public function __construct(
        public string $id,
        public string $name,
        public string $host,
        public string $provider,
        public string $country,
        public bool $isActive = true,
        public int $sortOrder = 0
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'host' => $this->host,
            'provider' => $this->provider,
            'country' => $this->country,
            'is_active' => $this->isActive,
            'sort_order' => $this->sortOrder,
        ];
    }
}
