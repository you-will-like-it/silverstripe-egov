<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Procedure;

use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * YAML-backed procedure catalogue (developer-owned part of a procedure).
 *
 * ```yaml
 * YouWillLikeIT\EGov\Procedure\ProcedureRegistry:
 *   procedures:
 *     '05-16':
 *       payload_fields:
 *         RequestedDocument:
 *           type: textarea
 *           label: 'Naziv dokumenta i kratak opis traženih podataka'
 *           required: true
 * ```
 *
 * Deadline overrides live in {@see \YouWillLikeIT\EGov\Service\DeadlineCalculator}.
 */
class ProcedureRegistry
{
    use Injectable;
    use Configurable;

    /**
     * URL segment of the public portal (must match the Director rule in _config/routes.yml).
     *
     * @config
     */
    private static string $portal_url_segment = 'e-uprava';

    /**
     * @config
     * @var array<string, array<string, mixed>>
     */
    private static array $procedures = [];

    public function portalBaseLink(): string
    {
        return Controller::join_links('/', (string) static::config()->get('portal_url_segment'));
    }

    public function portalLink(string $code): string
    {
        return Controller::join_links($this->portalBaseLink(), 'zahtjev', $code);
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(string $code): array
    {
        $all = (array) static::config()->get('procedures');
        $def = $all[$code] ?? [];
        return is_array($def) ? $def : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function payloadSchema(string $code): array
    {
        $schema = $this->definition($code)['payload_fields'] ?? [];
        if (!is_array($schema)) {
            return [];
        }
        $out = [];
        foreach ($schema as $name => $spec) {
            if (is_string($name) && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $name) && is_array($spec)) {
                $out[$name] = $spec;
            }
        }
        return $out;
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_map('strval', array_keys((array) static::config()->get('procedures')));
    }
}
