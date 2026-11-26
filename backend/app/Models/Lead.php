<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A lead captured by the public marketing site (v1.2.2).
 *
 * A lead is a person who asked for a demo before they have an
 * account, so leads are not tenant-scoped: they exist before any
 * organization does. The operator reviews them with
 * `php artisan leads:list`; there is no admin UI for them yet.
 *
 * Statuses: new (not yet contacted), contacted, qualified,
 * converted (signed up or started a trial), closed (not a fit or
 * no reply after follow-up).
 */
class Lead extends Model
{
    use HasUuids;

    public const SOURCE_DEMO_REQUEST = 'demo_request';

    public const SOURCE_PRICING = 'pricing';

    public const SOURCE_GETTING_STARTED = 'getting_started';

    public const STATUS_NEW = 'new';

    public const STATUS_CONTACTED = 'contacted';

    public const STATUS_QUALIFIED = 'qualified';

    public const STATUS_CONVERTED = 'converted';

    public const STATUS_CLOSED = 'closed';

    /**
     * @return array<int, string>
     */
    public static function sources(): array
    {
        return [
            self::SOURCE_DEMO_REQUEST,
            self::SOURCE_PRICING,
            self::SOURCE_GETTING_STARTED,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_NEW,
            self::STATUS_CONTACTED,
            self::STATUS_QUALIFIED,
            self::STATUS_CONVERTED,
            self::STATUS_CLOSED,
        ];
    }

    protected $fillable = [
        'name',
        'email',
        'company',
        'fleet_size',
        'message',
        'source',
        'status',
        'contacted_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'contacted_at' => 'datetime',
        ];
    }
}
