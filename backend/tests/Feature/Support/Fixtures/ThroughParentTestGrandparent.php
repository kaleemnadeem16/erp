<?php

declare(strict_types=1);

namespace Tests\Feature\Support\Fixtures;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixture for BelongsToCompanyThroughParentTest — directly company-scoped,
 * the role a real ancestor like Party plays for PartyContact.
 */
class ThroughParentTestGrandparent extends Model
{
    use BelongsToCompany;

    protected $table = 'through_parent_test_grandparents';

    protected $guarded = [];
}
