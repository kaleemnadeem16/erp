<?php

declare(strict_types=1);

namespace Tests\Support\Files;

use App\Modules\Files\Support\FileAccess;
use App\Modules\Files\Support\FileOperationContext;
use App\Modules\Files\Support\FileOwnerAdapter;
use App\Modules\Files\Support\FilePurpose;

/**
 * A fake file owner: an internally authored purpose (company logo: PNG/JPEG, 2 MB) and an externally supplied one (supplier document: PDF, 20 MB). It imports nothing
 * from another module and can be told to deny or to throw, so the kernel's fail-closed behaviour is observable.
 */
final class TestOwnerAdapter implements FileAccess, FileOwnerAdapter
{
    public const KEY = 'testing.owner';

    public const LOGO = 'testing.company-logo';

    public const DOCUMENT = 'testing.supplier-document';

    /** @var list<FileOperationContext> */
    public array $asked = [];

    public function __construct(private readonly bool $allow = true, private readonly bool $throws = false, private readonly int $logoMaxBytes = 2_097_152, private readonly int $logoMaxPixels = 40_000_000) {}

    public function ownerTypeKey(): string
    {
        return self::KEY;
    }

    public function purposes(): array
    {
        return [
            new FilePurpose(self::LOGO, ['image/png' => ['png'], 'image/jpeg' => ['jpg', 'jpeg']], $this->logoMaxBytes, false, $this->logoMaxPixels),
            new FilePurpose(self::DOCUMENT, ['application/pdf' => ['pdf']], 20_971_520, true),
        ];
    }

    public function access(): FileAccess
    {
        return $this;
    }

    public function authorise(FileOperationContext $context): bool
    {
        $this->asked[] = $context;
        if ($this->throws) {
            throw new \RuntimeException('the owner failed with a secret detail');
        }

        return $this->allow;
    }
}
