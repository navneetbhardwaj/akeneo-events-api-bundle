<?php

declare(strict_types=1);

namespace Trilix\EventsApiBundle\Tests\Unit\Model;

use Assert\InvalidArgumentException as AssertionInvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Trilix\EventsApiBundle\EventType\EventType;
use Trilix\EventsApiBundle\Model\ResolveEventType;
use Trilix\EventsApiBundle\Model\GenericEventInterface;
use Trilix\EventsApiBundle\Model\EventsHandler;
use Trilix\EventsApiBundle\Model\PayloadCanNotBeCreatedException;
use Trilix\EventsApiBundle\Model\OuterEventDispatcherInterface;
use Trilix\EventsApiBundle\OuterEvent\OuterEvent;
use Trilix\EventsApiBundle\OuterEvent\OuterEventBuilder;

class EventsHandlerTest extends TestCase
{
    /** @var ResolveEventType|MockObject */
    private $resolver;

    /** @var OuterEventBuilder|MockObject $builder */
    private $builder;

    /** @var OuterEventDispatcherInterface|MockObject $dispatcher */
    private $dispatcher;

    /** @var EventsHandler */
    private $handler;

    /** @var LoggerInterface|MockObject */
    private $logger;

    protected function setUp()
    {
        parent::setUp();
        $this->resolver = $this->getMockBuilder(ResolveEventType::class)->disableOriginalConstructor()->getMock();
        $this->builder = $this->getMockBuilder(OuterEventBuilder::class)->disableOriginalConstructor()->getMock();
        $this->dispatcher = $this->getMockBuilder(OuterEventDispatcherInterface::class)->getMock();
        $this->logger = $this->getMockBuilder(LoggerInterface::class)->getMock();

        $this->handler = new EventsHandler($this->resolver, $this->builder, $this->dispatcher, $this->logger);
    }

    /**
     * @test
     */
    public function throwsInvalidArgumentExceptionIfGenericEventSubjectIsNotObject(): void
    {
        $this->expectException(AssertionInvalidArgumentException::class);

        $this->handler->handle(
            new class implements GenericEventInterface {
                public function getSubject()
                {
                    return 'string';
                }
            }
        );
    }

    /**
     * @test
     */
    public function stopsHandlingIfEventTypeWasNotResolved(): void
    {
        $event = new class implements GenericEventInterface {
            public function getSubject()
            {
                return new Subject();
            }
        };

        $this->resolver->expects($this->once())->method('__invoke')
            ->with($event)->will($this->returnValue(null));

        $this->builder->expects($this->never())->method('withPayload');
        $this->builder->expects($this->never())->method('build');

        $this->dispatcher->expects($this->never())->method('dispatch');

        $this->logger->expects($this->never())->method('notice');

        $this->handler->handle($event);
    }

    /**
     * @test
     */
    public function catchesPayloadCanNotBeCreatedException(): void
    {
        $event = new class implements GenericEventInterface {
            public function getSubject()
            {
                return new Subject();
            }
        };

        $this->resolver->expects($this->once())->method('__invoke')
            ->with($event)->willThrowException(new PayloadCanNotBeCreatedException('testMessage'));

        $this->logger->expects($this->once())
            ->method('notice')
            ->with('testMessage');

        $this->handler->handle($event);
    }

    /**
     * @test
     */
    public function passesThrownExceptionNext(): void
    {
        $event = new class implements GenericEventInterface {
            public function getSubject()
            {
                return new Subject();
            }
        };

        $this->resolver->expects($this->once())->method('__invoke')
            ->with($event)->willThrowException(new RuntimeException('testMessage'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('testMessage');

        $this->logger->expects($this->never())
            ->method('notice');

        $this->handler->handle($event);
    }

    /**
     * @test
     */
    public function handlesEvent(): void
    {
        $event = new class implements GenericEventInterface {
            public function getSubject()
            {
                return new Subject();
            }
        };

        $payload = ['foo' => 'bar'];
        $eventType = new EventType('test_outer_event', $payload);
        $outerEvent = new OuterEvent('test_outer_event', ['foo' => 'bar'], time());

        $this->resolver->expects($this->once())->method('__invoke')
            ->with($event)->will($this->returnValue($eventType));

        $this->builder->expects($this->once())->method('withPayload')
            ->with($payload)->willReturnSelf();
        $this->builder->expects($this->once())->method('build')
            ->with('test_outer_event')->will($this->returnValue($outerEvent));

        $this->dispatcher->expects($this->once())->method('dispatch')->with($outerEvent);

        $this->logger->expects($this->never())
            ->method('notice');

        $this->handler->handle($event);
    }
}

class Subject {}
