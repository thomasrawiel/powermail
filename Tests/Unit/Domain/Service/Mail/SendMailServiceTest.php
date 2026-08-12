<?php

declare(strict_types=1);
namespace In2code\Powermail\Tests\Unit\Domain\Service\Mail;

use In2code\Powermail\Domain\Service\Mail\SendMailService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Class SendMailServiceTest
 *
 * Guards which values powermail hands to Fluid. Values that a website visitor can submit - the sender
 * of a mail to the receiver, the receiver of a mail to the sender - must never be used as a Fluid
 * template source.
 */
#[CoversClass(SendMailService::class)]
final class SendMailServiceTest extends UnitTestCase
{
    private function getSubjectForType(string $type): mixed
    {
        $subject = $this->getAccessibleMock(SendMailService::class, null, [], '', false);
        $subject->_set('type', $type);
        return $subject;
    }

    /**
     * Dataprovider getKeysAllowedToContainFluidReturnsArray()
     */
    public static function getKeysAllowedToContainFluidReturnsArrayDataProvider(): \Iterator
    {
        yield 'mail to the receiver: sender values come from the visitor' => [
            'receiver',
            ['receiverName', 'subject'],
        ];
        yield 'disclaimer mail: sender values come from the visitor' => [
            'disclaimer',
            ['receiverName', 'subject'],
        ];
        yield 'mail to the sender: receiver values come from the visitor' => [
            'sender',
            ['senderName', 'senderEmail', 'replyToName', 'replyToEmail', 'subject'],
        ];
        yield 'optin mail: receiver values come from the visitor' => [
            'optin',
            ['senderName', 'senderEmail', 'replyToName', 'replyToEmail', 'subject'],
        ];
        yield 'unknown mail type of another extension falls back to the subject only' => [
            'somethingCustom',
            ['subject'],
        ];
    }

    #[DataProvider('getKeysAllowedToContainFluidReturnsArrayDataProvider')]
    #[Test]
    public function getKeysAllowedToContainFluidReturnsArray(string $type, array $expectedResult): void
    {
        self::assertSame(
            $expectedResult,
            $this->getSubjectForType($type)->_call('getKeysAllowedToContainFluid')
        );
    }

    /**
     * Dataprovider visitorValuesAreNeverParsed()
     */
    public static function visitorValuesAreNeverParsedDataProvider(): \Iterator
    {
        yield 'sender name of a mail to the receiver' => ['receiver', 'senderName'];
        yield 'sender email of a mail to the receiver' => ['receiver', 'senderEmail'];
        yield 'reply to name of a mail to the receiver' => ['receiver', 'replyToName'];
        yield 'reply to email of a mail to the receiver' => ['receiver', 'replyToEmail'];
        yield 'sender name of a disclaimer mail' => ['disclaimer', 'senderName'];
        yield 'sender email of a disclaimer mail' => ['disclaimer', 'senderEmail'];
        yield 'receiver name of a mail to the sender' => ['sender', 'receiverName'];
        yield 'receiver name of an optin mail' => ['optin', 'receiverName'];
    }

    #[DataProvider('visitorValuesAreNeverParsedDataProvider')]
    #[Test]
    public function visitorValuesAreNeverParsed(string $type, string $key): void
    {
        self::assertNotContains(
            $key,
            $this->getSubjectForType($type)->_call('getKeysAllowedToContainFluid')
        );
    }

    /**
     * The receiver email is parsed once already, in
     * ReceiverMailReceiverPropertiesService::getEmailsFromFlexForm(), and by then it can contain
     * values that were substituted in from the submitted data. GeneralUtility::validEmail() accepts a
     * quoted local part, so being a valid address is no proof that a value is harmless.
     */
    #[DataProvider('mailTypeDataProvider')]
    #[Test]
    public function receiverEmailIsNeverParsedForAnyMailType(string $type): void
    {
        self::assertNotContains(
            'receiverEmail',
            $this->getSubjectForType($type)->_call('getKeysAllowedToContainFluid')
        );
    }

    /**
     * Dataprovider mailTypeDataProvider()
     */
    public static function mailTypeDataProvider(): \Iterator
    {
        yield 'receiver' => ['receiver'];
        yield 'sender' => ['sender'];
        yield 'optin' => ['optin'];
        yield 'disclaimer' => ['disclaimer'];
        yield 'unknown type' => ['somethingCustom'];
    }

    /**
     * A subject like "Message from {firstname}" is a documented feature
     */
    #[DataProvider('mailTypeDataProvider')]
    #[Test]
    public function subjectIsAlwaysParsed(string $type): void
    {
        self::assertContains(
            'subject',
            $this->getSubjectForType($type)->_call('getKeysAllowedToContainFluid')
        );
    }

    /**
     * The receiver name of a mail to the receiver comes from FlexForm, where Fluid is documented
     */
    #[Test]
    public function configuredReceiverNameIsParsedForAMailToTheReceiver(): void
    {
        self::assertContains(
            'receiverName',
            $this->getSubjectForType('receiver')->_call('getKeysAllowedToContainFluid')
        );
    }

    /**
     * The sender name of a mail to the sender comes from TypoScript or FlexForm
     */
    #[Test]
    public function configuredSenderNameIsParsedForAMailToTheSender(): void
    {
        self::assertContains(
            'senderName',
            $this->getSubjectForType('sender')->_call('getKeysAllowedToContainFluid')
        );
    }
}
