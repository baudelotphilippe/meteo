<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class ExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly MailerInterface $mailer, private readonly ParameterBagInterface $parameterBag)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ExceptionEvent::class => 'onKernelException',
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $sender = $this->parameterBag->get('mailer_sender');
        $alert_email = $this->parameterBag->get('alert_email');
        if (!$alert_email) {
            return;
        }

        $exception = $event->getThrowable();

        $request = $event->getRequest();
        $url = $request->getUri();
        $trace = $exception->getTraceAsString();

        $event->getRequest()->setMethod('POST');
        $email = (new Email())
            ->from(new Address($sender, 'error'))
            ->to($alert_email)
            ->subject('Il y a un probleme '.date('H:i:s'))
            ->text("
            url : {$url}
            
            message : {$exception->getMessage()}
            
            trace : {$trace}
            
        ");

        $this->mailer->send($email);
    }
}
