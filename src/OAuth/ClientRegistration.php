<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Validator\NoNulByte;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/** Request body of the dynamic client registration (RFC 7591), reduced to what Dojo accepts. */
final readonly class ClientRegistration
{
    /** Loopback only (RFC 8252): an agent on the user's own machine. The CSP of the consent page allows exactly these hosts. */
    public const REDIRECT_URI_PATTERN = '~^http://(?:127\.0\.0\.1|localhost)(?::\d{1,5})?(?:/[^\s#]*)?$~D';

    /** @param list<non-empty-string> $redirectUris */
    public function __construct(
        #[SerializedName('client_name')]
        #[Assert\NotBlank]
        #[Assert\Length(max: 128)]
        #[NoNulByte]
        public string $clientName,
        #[SerializedName('redirect_uris')]
        #[Assert\Count(min: 1, max: 5)]
        #[Assert\All([
            new Assert\Length(max: 255),
            new Assert\Regex(pattern: self::REDIRECT_URI_PATTERN, message: 'Only http redirect URIs on 127.0.0.1 or localhost are accepted.'),
        ])]
        public array $redirectUris,
    ) {
    }
}
