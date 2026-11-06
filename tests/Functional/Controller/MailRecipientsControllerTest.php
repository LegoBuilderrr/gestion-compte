<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\FunctionalTestCase;
use App\Tests\Support\Builder\BeneficiaryBuilder;
use App\Tests\Support\Builder\MembershipBuilder;

/**
 * The choice of the recipients of a mail (admin "Mails → Envoyer"): the filters
 * of the list of the members, open, without the list and without "Filtrer";
 * the button goes to the editor with the members found.
 *
 * @internal
 */
class MailRecipientsControllerTest extends FunctionalTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        static::loadFixtures(['period']);
    }

    public function testThePageIsTheOpenFiltersOfTheMembersWithOnlyTheMailButton(): void
    {
        $client = $this->loginAs('admin');

        $crawler = $client->request('GET', '/admin/mail/destinataires');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertSame('Envoyer un mail', trim($crawler->filterXPath('//h4')->text()));
        $this->assertSame(1, $crawler->filterXPath('//ul[@class="collapsible"]/li[@class="active"]')->count(), 'The filters are open.');
        $this->assertSame(1, $crawler->filterXPath('//input[@name="form[membernumber]"]')->count(), 'The filters of the members are there.');
        $this->assertSame(0, $crawler->filterXPath('//table')->count(), 'No list of members.');
        $this->assertSame(0, $crawler->filterXPath('//button[@name="form[submit]"]')->count(), 'No "Filtrer" button.');
        $this->assertSame(0, $crawler->filterXPath('//button[@name="form[csv]"]')->count());
        $this->assertSame(1, $crawler->filterXPath('//button[@name="form[mail]"]')->count());
        $this->assertSame('/admin/mail/', $crawler->filterXPath('//form[@name="form"]')->attr('action'));
        $this->assertSame("Vous pourrez encore ajouter des destinataires avant d'envoyer.", trim($crawler->filterXPath('//button[@name="form[mail]"]/following-sibling::p')->text()), 'The note is under the button.');
    }

    public function testTheButtonOpensTheEditorWithTheMembersFound(): void
    {
        $client = $this->loginAs('admin');
        $member = static::persist(MembershipBuilder::aMembership()
            ->withMainBeneficiary(BeneficiaryBuilder::aBeneficiary()->named('Zoé', 'Destinataire')->withAddress())
            ->build());
        $number = $member->getMemberNumber();

        $crawler = $client->request('GET', '/admin/mail/destinataires');
        $crawler = $client->submit($crawler->selectButton('Envoyer un mail')->form(['form[membernumber]' => (string) $number]));

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertSame(1, $crawler->filterXPath('//textarea[@name="form[message]"]')->count(), 'The editor of the mail.');
        $this->assertStringContainsString('Zoé', $crawler->filterXPath('//input[@name="form[to][0]"]')->attr('value'));
    }
}
