<?php

declare(strict_types=1);

namespace App\Versioning;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The restore and discard rules for skills and testcases, with the messages shown to the user. */
final class VersionWorkflow
{
    /** @return array{0: 'success'|'error', 1: string} flash type and message; the caller flushes */
    public function restore(VersionedInterface $entity, int $number): array
    {
        $version = $entity->findVersion($number) ?? throw new NotFoundHttpException();

        // A draft always exists when the requested version is the draft, so this also covers "a draft cannot be restored".
        if (null !== $entity->getDraft()) {
            return ['error', 'A draft exists: save it as final or discard it before restoring a version.'];
        }

        $restored = $entity->restore($version);

        return ['success', \sprintf('Version %d restored as version %d.', $number, $restored->getNumber())];
    }

    /** @return array{0: 'success'|'error', 1: string} flash type and message; the caller flushes */
    public function discardDraft(VersionedInterface $entity): array
    {
        if (null === $entity->getDraft()) {
            return ['error', 'There is no draft to discard.'];
        }

        $entity->discardDraft();

        return ['success', 'Draft discarded.'];
    }
}
