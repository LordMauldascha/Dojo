<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\WriteRateLimitSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class WriteRateLimitSubscriberTest extends TestCase
{
    public function testTheThirdChangeInTheWindowIsRefused(): void
    {
        $subscriber = $this->subscriber('alice', limit: 2);

        $subscriber->onRequest($this->event('POST'));
        $subscriber->onRequest($this->event('POST'));

        $this->expectException(TooManyRequestsHttpException::class);
        $subscriber->onRequest($this->event('POST'));
    }

    public function testReadingIsNeverLimited(): void
    {
        $subscriber = $this->subscriber('alice', limit: 1);

        for ($i = 0; 20 > $i; ++$i) {
            $subscriber->onRequest($this->event('GET'));
        }

        $this->addToAssertionCount(1);
    }

    public function testSubRequestsAndAnonymousVisitorsAreNotCounted(): void
    {
        $subscriber = $this->subscriber('alice', limit: 1);
        $anonymous = $this->subscriber(null, limit: 1);

        for ($i = 0; 5 > $i; ++$i) {
            $subscriber->onRequest($this->event('POST', HttpKernelInterface::SUB_REQUEST));
            $anonymous->onRequest($this->event('POST'));
        }

        $this->addToAssertionCount(1);
    }

    public function testEachUserHasHisOwnBudget(): void
    {
        $factory = $this->factory(1);
        $alice = $this->subscriber('alice', 1, $factory);
        $bob = $this->subscriber('bob', 1, $factory);

        $alice->onRequest($this->event('POST'));
        $bob->onRequest($this->event('POST'));

        $this->addToAssertionCount(1);
    }

    private function factory(int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(['id' => 'test', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 minute'], new InMemoryStorage());
    }

    private function subscriber(?string $username, int $limit, ?RateLimiterFactory $factory = null): WriteRateLimitSubscriber
    {
        $tokenStorage = new TokenStorage();
        if (null !== $username) {
            $user = new User();
            $user->setUsername($username);
            $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        }

        return new WriteRateLimitSubscriber($factory ?? $this->factory($limit), $tokenStorage);
    }

    private function event(string $method, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), Request::create('/anything', $method), $type);
    }
}
