<?php

namespace App;

/**
 * Who performed an audited action.
 *
 * A user actor is a signed-in member; a system actor is a console
 * command, a queued job, or anything else that runs without a user.
 * System rows have a null actor_id.
 */
enum AuditActorType: string
{
    case User = 'user';
    case System = 'system';
}
