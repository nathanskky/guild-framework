<?php

declare(strict_types=1);

namespace Guild\Framework\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Typed reads from a submitted form. Anything missing or of the wrong shape
 * reads as empty rather than failing: validation decides what that means.
 *
 * @internal
 */
final readonly class FormInput
{
    /**
     * @param  array<array-key, mixed>  $values
     */
    private function __construct(private array $values)
    {
    }

    public static function from(ServerRequestInterface $request): self
    {
        $body = $request->getParsedBody();

        return new self(is_array($body) ? $body : []);
    }

    public function string(string $name): string
    {
        $value = $this->values[$name] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    public function checked(string $name): bool
    {
        return ($this->values[$name] ?? null) === '1';
    }

    /**
     * @return list<string>
     */
    public function strings(string $name): array
    {
        $values = $this->values[$name] ?? [];

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter($values, is_string(...))));
    }
}
