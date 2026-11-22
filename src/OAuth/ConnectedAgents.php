<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AccessToken;
use League\Bundle\OAuth2ServerBundle\Model\AuthorizationCode;
use League\Bundle\OAuth2ServerBundle\Model\RefreshToken;

/** The agents a user has approved: listing and revoking only touches that user's tokens, other users of the same client stay connected. */
final readonly class ConnectedAgents
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClientManagerInterface $clients,
    ) {
    }

    /**
     * An agent stays connected as long as it holds a valid refresh token.
     *
     * @return list<array{clientId: string, name: string, validUntil: \DateTimeImmutable}>
     */
    public function forUser(User $user): array
    {
        /** @var list<array{clientId: string, name: string, validUntil: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('c.identifier AS clientId', 'c.name AS name', 'MAX(rt.expiry) AS validUntil')
            ->from(RefreshToken::class, 'rt')
            ->join('rt.accessToken', 'at')
            ->join('at.client', 'c')
            ->where('at.userIdentifier = :user')
            ->andWhere('rt.revoked = false')
            ->andWhere('rt.expiry > :now')
            ->groupBy('c.identifier', 'c.name')
            ->orderBy('c.name')
            ->addOrderBy('c.identifier')
            ->setParameter('user', $user->getUserIdentifier())
            ->setParameter('now', new \DateTimeImmutable(), Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'clientId' => $row['clientId'],
            'name' => $row['name'],
            'validUntil' => new \DateTimeImmutable($row['validUntil']),
        ], $rows);
    }

    /** Revokes access tokens, refresh tokens and pending authorization codes of this user for the client. */
    public function revoke(User $user, string $clientId): void
    {
        $client = $this->clients->find($clientId);
        if (null === $client) {
            return;
        }

        $this->entityManager->wrapInTransaction(function () use ($user, $client): void {
            $userIdentifier = $user->getUserIdentifier();
            $ownTokens = $this->entityManager->createQueryBuilder()
                ->select('own.identifier')
                ->from(AccessToken::class, 'own')
                ->where('own.userIdentifier = :user AND own.client = :client');

            $this->entityManager->createQueryBuilder()
                ->update(RefreshToken::class, 'rt')
                ->set('rt.revoked', ':revoked')
                ->where('rt.accessToken IN ('.$ownTokens->getDQL().')')
                ->setParameter('revoked', true)
                ->setParameter('user', $userIdentifier)
                ->setParameter('client', $client)
                ->getQuery()
                ->execute();

            foreach ([AccessToken::class, AuthorizationCode::class] as $class) {
                $this->entityManager->createQueryBuilder()
                    ->update($class, 't')
                    ->set('t.revoked', ':revoked')
                    ->where('t.userIdentifier = :user AND t.client = :client')
                    ->setParameter('revoked', true)
                    ->setParameter('user', $userIdentifier)
                    ->setParameter('client', $client)
                    ->getQuery()
                    ->execute();
            }
        });
    }
}
