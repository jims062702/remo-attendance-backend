<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\Daily\SubmitTrackerRequest;
use App\Models\User;

/**
 * An administrator correcting a tracker entry.
 *
 * Extends the tasker's own submission request rather than restating its rules,
 * and that is the whole point: task IDs are required, screenshots are required,
 * and the number of IDs has to match the number of tasks declared. A separate
 * rule set here would make the admin screen a back door around the checks the
 * tasker cannot get past -- and corrected entries are exactly the ones most
 * likely to be looked at later.
 *
 * Only `authorize()` differs.
 */
class UpdateTrackerEntryRequest extends SubmitTrackerRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }
}
