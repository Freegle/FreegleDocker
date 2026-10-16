<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One time server code used a member's experiment arm to decide what they got.
 *
 * @see ../../database/migrations/2026_10_16_000001_create_ux_experiment_exposures_table.php
 *
 * A plain model, deliberately not Auditable: the table is analytics, written in bulk by the senders,
 * and an audit row for each would double the writes. It exists so the account merge can move a
 * member's rows to the surviving account and so the erasure paths can name the table.
 *
 * @property int $id
 * @property string $experiment
 * @property string $arm
 * @property int $userid
 * @property string $subject_type
 * @property int $subject_id
 * @property string|null $stratum
 * @property \Illuminate\Support\Carbon $exposed_at
 * @property array|null $detail
 */
class UxExperimentExposure extends Model
{
    protected $table = 'ux_experiment_exposures';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'exposed_at' => 'datetime',
        'detail' => 'array',
    ];
}
