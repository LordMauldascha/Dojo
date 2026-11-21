<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\HuggingFaceModel;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class HuggingFaceModelData
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        #[Assert\Regex('~'.HuggingFaceModel::ID_PATTERN.'~')]
        public string $modelId = '',
    ) {
    }
}
