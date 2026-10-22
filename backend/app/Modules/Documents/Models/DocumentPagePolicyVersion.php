<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Models\Model;
use App\Modules\Documents\Support\PageBandMode;
use App\Modules\Documents\Support\PageFormat;
use App\Modules\Documents\Support\PageOrientation;
use App\Modules\Documents\Support\PagePolicyConfiguration;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * One immutable version of a page policy: paper, orientation, margins, header/footer bands and compatible layouts. type_key '*' fits every type. The
 * database refuses every update and delete.
 *
 * @property int $id
 * @property string $type_key
 * @property string $policy_key
 * @property int $version
 * @property string $format
 * @property string $orientation
 * @property string $width_mm
 * @property string $height_mm
 * @property string $margin_top_mm
 * @property string $margin_right_mm
 * @property string $margin_bottom_mm
 * @property string $margin_left_mm
 * @property string $first_header_mode
 * @property string $continuation_header_mode
 * @property string $first_footer_mode
 * @property string $continuation_footer_mode
 * @property list<string> $compatible_layouts
 * @property int|null $created_by
 * @property Carbon $created_at
 */
class DocumentPagePolicyVersion extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['compatible_layouts' => 'array', 'created_at' => 'datetime'];

    public function configuration(): PagePolicyConfiguration
    {
        return new PagePolicyConfiguration(
            PageFormat::from($this->format), PageOrientation::from($this->orientation),
            $this->margin_top_mm, $this->margin_right_mm, $this->margin_bottom_mm, $this->margin_left_mm,
            PageBandMode::from($this->first_header_mode), PageBandMode::from($this->continuation_header_mode),
            PageBandMode::from($this->first_footer_mode), PageBandMode::from($this->continuation_footer_mode),
            $this->compatible_layouts,
        );
    }
}
