<?php

declare(strict_types=1);

namespace Mautic\SmsBundle\Tests\Functional;

use Mautic\CampaignBundle\Executioner\ContactFinder\Limiter\ContactLimiter;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Entity\ListLead;
use Mautic\SmsBundle\Broadcast\BroadcastQuery;
use Mautic\SmsBundle\Entity\Sms;
use Mautic\SmsBundle\Entity\Stat;
use Mautic\SmsBundle\Model\SmsModel;

final class BroadcastQueryFunctionalTest extends MauticMysqlTestCase
{
    use CreateEntitiesTrait;

    public function testSegmentContactIsPendingWhenNothingHasBeenSent(): void
    {
        [$parent, , $contact] = $this->createSegmentBroadcast();

        self::assertSame([$contact->getId()], $this->getPendingContactIds($parent));
    }

    public function testContactWhoReceivedTheSmsItselfIsNotPending(): void
    {
        [$parent, , $contact] = $this->createSegmentBroadcast();

        $this->createStat($parent, $contact);

        self::assertSame([], $this->getPendingContactIds($parent));
    }

    public function testContactWhoReceivedATranslationIsNotPending(): void
    {
        [$parent, $translation, $contact] = $this->createSegmentBroadcast();

        // A broadcast attributes the statistic to the translation the contact was
        // actually sent, not to the message the broadcast runs on.
        $this->createStat($translation, $contact);

        self::assertSame([], $this->getPendingContactIds($parent));
    }

    public function testContactWhoReceivedAnUnrelatedSmsIsStillPending(): void
    {
        [$parent, , $contact] = $this->createSegmentBroadcast();

        $unrelated = $this->createAnSms('Unrelated SMS', 'Hi');
        $this->em->persist($unrelated);
        $this->em->flush();

        $this->createStat($unrelated, $contact);

        self::assertSame([$contact->getId()], $this->getPendingContactIds($parent));
    }

    /**
     * @return array{0: Sms, 1: Sms, 2: Lead}
     */
    private function createSegmentBroadcast(): array
    {
        $segment = new LeadList();
        $segment->setName('SMS translation segment');
        $segment->setPublicName('SMS translation segment');
        $segment->setAlias('sms-translation-segment');

        $contact = new Lead();
        $contact->setMobile('123456789');

        $membership = new ListLead();
        $membership->setLead($contact);
        $membership->setList($segment);
        $membership->setDateAdded(new \DateTime());

        $parent = $this->createAnSms('English segment SMS', 'Hello');
        $parent->setSmsType('list');
        $parent->addList($segment);

        $translation = $this->createAnSms('French segment SMS', 'Bonjour', true, 'fr_FR');
        $translation->setSmsType('list');
        $translation->setTranslationParent($parent);

        $this->em->persist($segment);
        $this->em->persist($contact);
        $this->em->persist($membership);
        $this->em->persist($parent);
        $this->em->persist($translation);
        $this->em->flush();

        // Reload so the parent knows its translations, the way a broadcast loads it.
        $parentId      = $parent->getId();
        $translationId = $translation->getId();
        $contactId     = $contact->getId();
        $this->em->clear();

        $parent      = $this->em->find(Sms::class, $parentId);
        $translation = $this->em->find(Sms::class, $translationId);
        $contact     = $this->em->find(Lead::class, $contactId);
        self::assertInstanceOf(Sms::class, $parent);
        self::assertInstanceOf(Sms::class, $translation);
        self::assertInstanceOf(Lead::class, $contact);

        return [$parent, $translation, $contact];
    }

    private function createStat(Sms $sms, Lead $contact): void
    {
        $stat = new Stat();
        $stat->setSms($sms);
        $stat->setLead($contact);
        $stat->setDateSent(new \DateTime());
        $stat->setSource('sms');

        $this->em->persist($stat);
        $this->em->flush();
    }

    /**
     * @return int[]
     */
    private function getPendingContactIds(Sms $sms): array
    {
        /** @var SmsModel $smsModel */
        $smsModel = $this->getContainer()->get(SmsModel::class);

        $broadcastQuery = new BroadcastQuery($this->em, $smsModel->getRepository());
        $pending        = $broadcastQuery->getPendingContacts($sms, new ContactLimiter(100));

        return array_map('intval', array_column($pending, 'id'));
    }
}
