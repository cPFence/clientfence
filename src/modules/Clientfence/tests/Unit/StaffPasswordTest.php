<?php

declare(strict_types=1);

/**
 * ClientFence: console.php clientfence:staff-password (CF-79), for an owner locked out of the admin. The staff
 * members and the password change are fakes; the hidden questions are answered by a stand-in question helper.
 */

use ClientFence\Mod\Clientfence\Commands\StaffPassword;
use ClientFence\Mod\Staff\Entity\Admin;
use ClientFence\Mod\Staff\Entity\AdminPasswordReset;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Tester\CommandTester;

use function Tests\Helpers\container;

/** Owner #1 (active) and a former employee #2 (inactive); the password changes land in $world->changed. */
function staffPasswordWorld(array $answers = []): object
{
    $world = new stdClass();
    $world->changed = [];
    $world->signedOut = [];
    $world->flushed = 0;
    $owner = (new Admin())->setEmail('owner@example.com')->setName('Owner')->setStatus(Admin::STATUS_ACTIVE);
    $former = (new Admin())->setEmail('former@example.com')->setName('Former')->setStatus(Admin::STATUS_INACTIVE);
    foreach ([1 => $owner, 2 => $former] as $id => $admin) {
        (new ReflectionProperty(Admin::class, 'id'))->setValue($admin, $id);
    }
    $world->former = $former;

    $staff = Mockery::mock();
    $staff->allows('findOneBy')->andReturnUsing(fn (array $by): ?Admin => ['owner@example.com' => $owner, 'former@example.com' => $former][$by['email']] ?? null);
    $staff->allows('findBy')->andReturn([$owner, $former]);
    $resets = Mockery::mock();
    $resets->allows('changePassword')->andReturnUsing(function (Admin $admin, string $hash) use ($world): void {
        $world->changed[$admin->getId()] = $hash;
    });
    $em = Mockery::mock();
    $em->allows('getRepository')->andReturnUsing(fn (string $class): object => $class === AdminPasswordReset::class ? $resets : $staff);
    $em->allows('flush')->andReturnUsing(function () use ($world): void {
        ++$world->flushed;
    });

    $di = container();
    $di['em'] = $em;
    $di['tools'] = new ClientFence\Tools();
    $di['validator'] = new ClientFence\Validate();
    $di['password'] = new class {
        public function hashIt(string $password): string
        {
            return 'hash:' . $password;
        }
    };
    $di['events_manager'] = Mockery::mock()->shouldIgnoreMissing();
    $di['logger'] = Mockery::mock()->shouldIgnoreMissing();
    $profile = Mockery::mock();
    $profile->allows('invalidateSessions')->andReturnUsing(function (string $type, int $id) use ($world): bool {
        $world->signedOut[] = $id;

        return true;
    });
    $di['mod_service'] = $di->protect(fn (): object => $profile);

    $command = new StaffPassword();
    $command->setDi($di);
    $command->setHelperSet(new HelperSet(['question' => new class($answers) extends QuestionHelper {
        public function __construct(private array $answers)
        {
        }

        public function ask(InputInterface $input, OutputInterface $output, Question $question): mixed
        {
            // Nothing to read, as on a console without a terminal.
            return $this->answers === [] ? throw new Symfony\Component\Console\Exception\MissingInputException('Aborted.') : array_shift($this->answers);
        }
    }]));
    $world->tester = new CommandTester($command);

    return $world;
}

test('without an email it lists the staff; an unknown email changes nothing', function (): void {
    $world = staffPasswordWorld();
    expect($world->tester->execute([]))->toBe(Command::SUCCESS)
        ->and($world->tester->getDisplay())->toContain('owner@example.com  Owner (active)')->toContain('former@example.com  Former (inactive)');

    expect($world->tester->execute(['email' => 'nobody@example.com']))->toBe(Command::FAILURE)
        ->and($world->tester->getDisplay())->toContain('No staff member has the email address nobody@example.com.')
        ->and($world->changed)->toBe([]);
});

test('typed twice it sets the password and signs the staff member out; a mismatch or a weak one changes nothing', function (): void {
    $world = staffPasswordWorld(['Locked0ut!', 'Locked0ut!']);
    expect($world->tester->execute(['email' => 'owner@example.com'], ['interactive' => true]))->toBe(Command::SUCCESS)
        ->and($world->changed)->toBe([1 => 'hash:Locked0ut!'])
        ->and($world->signedOut)->toBe([1])
        ->and($world->tester->getDisplay())->not->toContain('Locked0ut!');

    $world = staffPasswordWorld(['Locked0ut!', 'Locked0ut?']);
    expect($world->tester->execute(['email' => 'owner@example.com'], ['interactive' => true]))->toBe(Command::FAILURE)
        ->and($world->changed)->toBe([]);

    // Spaces are part of the password, as the sign-in compares it; only the line ending goes.
    $world = staffPasswordWorld([" Locked0ut! \n", " Locked0ut! \n"]);
    $world->tester->execute(['email' => 'owner@example.com'], ['interactive' => true]);
    expect($world->changed)->toBe([1 => 'hash: Locked0ut! ']);

    $world = staffPasswordWorld(['short', 'short']);
    expect(fn () => $world->tester->execute(['email' => 'owner@example.com'], ['interactive' => true]))->toThrow(ClientFence\InformationException::class)
        ->and($world->changed)->toBe([]);
});

test('without a terminal it makes a strong password and shows it once; --activate sets an inactive member active', function (): void {
    $world = staffPasswordWorld();
    expect($world->tester->execute(['email' => 'former@example.com'], ['interactive' => false]))->toBe(Command::SUCCESS);
    preg_match('/The new password \(shown once\): (\S+)/', $world->tester->getDisplay(), $made);
    expect($world->changed)->toBe([2 => 'hash:' . $made[1]])
        ->and((new ClientFence\Validate())->isPasswordStrong($made[1]))->toBeTrue()
        ->and($world->tester->getDisplay())->toContain('run it again with --activate')
        ->and($world->former->getStatus())->toBe(Admin::STATUS_INACTIVE);

    // Asked, but nothing to read: made all the same.
    $world = staffPasswordWorld();
    expect($world->tester->execute(['email' => 'owner@example.com'], ['interactive' => true]))->toBe(Command::SUCCESS)
        ->and($world->tester->getDisplay())->toContain('The new password (shown once): ')
        ->and(array_keys($world->changed))->toBe([1]);

    $world = staffPasswordWorld();
    $world->tester->execute(['email' => 'former@example.com', '--activate' => true], ['interactive' => false]);
    expect($world->former->getStatus())->toBe(Admin::STATUS_ACTIVE)
        ->and($world->flushed)->toBe(1)
        ->and($world->tester->getDisplay())->toContain('Former is active again.')->not->toContain('run it again');
});
