<?php

declare(strict_types=1);

namespace Paragon\Core\Tests;

use Paragon\Core\Container;
use PHPUnit\Framework\TestCase;
use RuntimeException;

interface SampleInterface
{
}

final class SampleImplementation implements SampleInterface
{
}

final class NeedsSample
{
    public function __construct(public readonly SampleInterface $sample)
    {
    }
}

final class NeedsNothing
{
}

final class HasDefault
{
    public function __construct(public readonly int $count = 7)
    {
    }
}

final class HasUntypedRequired
{
    /** @param mixed $whatever */
    public function __construct(public $whatever)
    {
    }
}

final class CycleA
{
    public function __construct(public CycleB $b)
    {
    }
}

final class CycleB
{
    public function __construct(public CycleA $a)
    {
    }
}

final class ContainerTest extends TestCase
{
    public function test_it_autowires_a_class_with_no_constructor(): void
    {
        $container = new Container();

        self::assertInstanceOf(NeedsNothing::class, $container->get(NeedsNothing::class));
    }

    public function test_it_autowires_constructor_dependencies_from_bindings(): void
    {
        $container = new Container();
        $container->bind(SampleInterface::class, static fn (): SampleInterface => new SampleImplementation());

        $resolved = $container->get(NeedsSample::class);

        self::assertInstanceOf(NeedsSample::class, $resolved);
        self::assertInstanceOf(SampleImplementation::class, $resolved->sample);
    }

    public function test_singletons_return_the_same_instance_and_bindings_do_not(): void
    {
        $container = new Container();
        $container->singleton('shared', static fn (): object => new \stdClass());
        $container->bind('fresh', static fn (): object => new \stdClass());

        self::assertSame($container->get('shared'), $container->get('shared'));
        self::assertNotSame($container->get('fresh'), $container->get('fresh'));
    }

    public function test_an_alias_resolves_to_the_concrete_binding(): void
    {
        $container = new Container();
        $container->singleton(SampleImplementation::class, static fn (): object => new SampleImplementation());
        $container->alias(SampleInterface::class, SampleImplementation::class);

        self::assertSame(
            $container->get(SampleImplementation::class),
            $container->get(SampleInterface::class)
        );
    }

    /**
     * An unbound interface must fail loudly. config/services.php documents why:
     * guessing an implementation would make wiring invisible, which is how a
     * test silently acquires a live network adapter.
     */
    public function test_an_unbound_interface_throws_rather_than_guessing(): void
    {
        $container = new Container();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('interface or abstract class with no binding');

        $container->get(SampleInterface::class);
    }

    public function test_it_falls_back_to_a_default_value_for_builtin_parameters(): void
    {
        $container = new Container();

        self::assertSame(7, $container->get(HasDefault::class)->count);
    }

    public function test_it_reports_an_unresolvable_parameter_by_name(): void
    {
        $container = new Container();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('$whatever');

        $container->get(HasUntypedRequired::class);
    }

    /**
     * Without cycle detection this recurses until PHP exhausts its stack,
     * which reports as a fatal memory error rather than the actual problem.
     */
    public function test_it_detects_a_circular_dependency(): void
    {
        $container = new Container();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Circular dependency');

        $container->get(CycleA::class);
    }

    public function test_a_failed_resolution_does_not_corrupt_the_build_stack(): void
    {
        $container = new Container();

        try {
            $container->get(CycleA::class);
        } catch (RuntimeException) {
            // Expected. The point is what happens next.
        }

        // If the stack leaked, this unrelated resolution would falsely report
        // a cycle. This is the regression the finally-block in get() prevents.
        self::assertInstanceOf(NeedsNothing::class, $container->get(NeedsNothing::class));
    }

    public function test_instances_are_returned_as_registered(): void
    {
        $container = new Container();
        $object = new \stdClass();
        $container->instance('thing', $object);

        self::assertSame($object, $container->get('thing'));
        self::assertTrue($container->has('thing'));
    }
}
