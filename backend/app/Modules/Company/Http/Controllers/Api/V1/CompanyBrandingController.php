<?php

declare(strict_types=1);

namespace App\Modules\Company\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Company\Actions\ReadCompanyBrand;
use App\Modules\Company\Actions\ReadCompanyBrandLogo;
use App\Modules\Company\Actions\SaveCompanyBranding;
use App\Modules\Company\Http\Requests\SaveCompanyBrandingRequest;
use App\Modules\Company\Http\Resources\CompanyBrandingResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The company's document branding (issue #23, ADR 0162). Readable by any signed-in member of the company (it is printed on documents handed out); changes need `company.branding.manage`. */
class CompanyBrandingController extends Controller
{
    public function show(ReadCompanyBrand $brand): CompanyBrandingResource
    {
        return new CompanyBrandingResource($brand->latest());
    }

    public function update(SaveCompanyBrandingRequest $request, SaveCompanyBranding $save, ReadCompanyBrand $brand): CompanyBrandingResource
    {
        $changes = array_intersect_key($request->validated(), array_flip(['display_name', 'tagline', 'remove_logo']));
        if ($request->has('remove_logo')) {
            $changes['remove_logo'] = $request->boolean('remove_logo');
        }
        $file = $request->file('logo');
        $stream = $file === null ? null : fopen($file->getRealPath(), 'rb');
        try {
            $save($request->user(), $changes, $stream, (string) $request->validated('reason'));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return new CompanyBrandingResource($brand->latest());
    }

    /** The current logo, exactly the sanitised file that is printed. Private and never cached; a plain 404 when there is none. */
    public function logo(Request $request, ReadCompanyBrand $brand, ReadCompanyBrandLogo $read): Response
    {
        $current = $brand->current();
        abort_if($current === null || $current->logo === null, 404, 'The company has no logo.');
        $bytes = $read->execute($current->logo, $request->user()?->id);

        return response($bytes, 200, [
            'Content-Type' => $current->logo->mediaType, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'inline; filename="logo.'.($current->logo->mediaType === 'image/png' ? 'png' : 'jpg').'"', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
