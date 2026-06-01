<?php

declare(strict_types=1);

namespace Callisto\Sdk\Model;

final readonly class Paginated
{
    /** @param list<mixed> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $perPage,
        public int $currentPage,
        public ?int $next,
        public ?int $previous,
        public int $totalPages,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param callable(array): mixed $itemFactory
     */
    public static function fromArray(array $data, callable $itemFactory): self
    {
        return new self(
            items: array_map($itemFactory, $data['items'] ?? []),
            total: (int) ($data['total'] ?? 0),
            perPage: (int) ($data['per_page'] ?? 0),
            currentPage: (int) ($data['current_page'] ?? 0),
            next: isset($data['next']) ? (int) $data['next'] : null,
            previous: isset($data['previous']) ? (int) $data['previous'] : null,
            totalPages: (int) ($data['total_pages'] ?? 0),
        );
    }
}
