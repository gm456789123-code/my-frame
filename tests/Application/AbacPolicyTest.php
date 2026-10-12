<?php

declare(strict_types=1);

use App\Application\Security\AbacPolicy;
use App\Application\Security\SecurityUser;
use App\Application\Security\UserRole;
use App\Domain\ValueObject\ClassificationLevel;
use App\Domain\ValueObject\TenantId;
use App\Domain\ValueObject\UserId;

it('allows admin full access', function (): void {
    $policy = new AbacPolicy();
    $admin = new SecurityUser(new UserId(1), new TenantId('tenant-a'), UserRole::Administrator);

    expect($policy->can($admin, 'page:create'))->toBeTrue()
        ->and($policy->can($admin, 'page:delete'))->toBeTrue()
        ->and($policy->can($admin, 'vault:read', ClassificationLevel::Confidential))->toBeTrue();
});

it('restricts confidential vault access from editors and subscribers', function (): void {
    $policy = new AbacPolicy();
    $editor = new SecurityUser(new UserId(2), new TenantId('tenant-a'), UserRole::Editor);
    $subscriber = new SecurityUser(new UserId(3), new TenantId('tenant-a'), UserRole::Subscriber);

    expect($policy->can($editor, 'vault:read', ClassificationLevel::Restricted))->toBeTrue()
        ->and($policy->can($editor, 'vault:read', ClassificationLevel::Confidential))->toBeFalse()
        ->and($policy->can($subscriber, 'vault:read', ClassificationLevel::Internal))->toBeFalse()
        ->and($policy->can($subscriber, 'vault:read', ClassificationLevel::Public))->toBeTrue();
});
