<?php

namespace App\Controller;

use App\Entity\Beneficiary;
use App\Entity\EmailTemplate;
use App\Entity\Shift;
use App\Entity\User;
use App\Form\AutocompleteBeneficiaryCollectionType;
use App\Form\MarkdownEditorType;
use App\Mail\MailMetadata;
use App\Mail\MailQueueStatus;
use App\Service\MailerService;
use App\Service\SearchUserFormHelper;
use Michelf\Markdown;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Annotation\Route;
use Twig\Error\Error as TwigError;
use Twig\TemplateWrapper;

/**
 * Email controller.
 *
 * @Route("admin/mail")
 *
 * @Security("is_granted('ROLE_USER_MANAGER')")
 */
class MailController extends AbstractController
{
    /**
     * Choice of the recipients: the filters of the list of the members, nothing
     * else. The button sends them to the editor with the members found.
     *
     * @Route("/destinataires", name="mail_recipients", methods={"GET"})
     */
    public function recipientsAction(SearchUserFormHelper $formHelper): Response
    {
        $form = $formHelper->createMemberFilterForm($this->createFormBuilder(), [
            'withdrawn' => 1,
            'sort' => 'm.member_number',
            'dir' => 'ASC',
        ]);

        return $this->render('admin/mail/recipients.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * Edit a message.
     *
     * @Route("/to/{id}", name="mail_edit_one_beneficiary", methods={"GET","POST"})
     */
    public function editActionOneBeneficiary(Request $request, Beneficiary $beneficiary, MailerService $mailer_service)
    {
        $mailform = $this->getMailForm($mailer_service, [$beneficiary]);
        $non_members = $this->getNonMemberEmails();

        return $this->render('admin/mail/send.html.twig', [
            'form' => $mailform->createView(),
            'non_members' => $non_members,
        ]);
    }

    /**
     * @Route("/to_bucket/{id}", name="mail_bucketshift", methods={"GET","POST"})
     */
    public function mailBucketShift(Request $request, Shift $shift, MailerService $mailer_service)
    {
        if ($shift) {
            $em = $this->getDoctrine()->getManager();
            $shifts = $em->getRepository(Shift::class)->findBy(['job' => $shift->getJob(), 'start' => $shift->getStart(), 'end' => $shift->getEnd()]);
            $beneficiaries = [];
            foreach ($shifts as $shift) {
                if ($shift->getShifter()) {
                    $beneficiaries[] = $shift->getShifter();
                }
            }
            $mailform = $this->getMailForm($mailer_service, $beneficiaries);
            $non_members = $this->getNonMemberEmails();

            return $this->render('admin/mail/send.html.twig', [
                'form' => $mailform->createView(),
                'non_members' => $non_members,
            ]);
        }
    }

    /**
     * Edit a message.
     *
     * @Route("/", name="mail_edit", methods={"GET","POST"})
     */
    public function editAction(Request $request, SearchUserFormHelper $formHelper, MailerService $mailer_service)
    {
        $form = $formHelper->getSearchForm($this->createFormBuilder());
        $form->handleRequest($request);
        $qb = $formHelper->initSearchQuery($this->getDoctrine()->getManager());

        $to = [];
        if ($form->isSubmitted() && $form->isValid()) {
            $qb = $formHelper->processSearchFormData($form, $qb);
            $members = $qb->getQuery()->getResult();
            foreach ($members as $member) {
                foreach ($member->getBeneficiaries() as $beneficiary) {
                    $to[] = $beneficiary;
                }
            }
        }
        $non_members = $this->getNonMemberEmails();

        $mailform = $this->getMailForm($mailer_service, $to);

        return $this->render('admin/mail/send.html.twig', [
            'form' => $mailform->createView(),
            'non_members' => $non_members,
        ]);
    }

    /**
     * Send a message.
     *
     * When the message cannot be sent the page of the form is shown again with
     * everything that was typed: nobody loses the message they wrote.
     *
     * @Route("/send", name="mail_send", methods={"POST"})
     */
    public function sendAction(Request $request, MailerInterface $mailer, MailerService $mailer_service, MailQueueStatus $queueStatus)
    {
        $mailform = $this->getMailForm($mailer_service);
        $mailform->handleRequest($request);
        if (!$mailform->isSubmitted()) {
            return $this->redirectToRoute('mail_edit');
        }

        $errors = [];
        foreach ($mailform->getErrors(true) as $error) {
            $errors[] = $this->describeFormError($error);
        }
        if ($errors) {
            return $this->showSendForm($mailform, $errors);
        }

        $em = $this->getDoctrine()->getManager();
        // beneficiaries
        $beneficiaries = $mailform->get('to')->getData();
        // non-member
        $cci = $mailform->get('cci')->getData();
        $nonMembers = json_decode($cci) ?: [];
        foreach ($nonMembers as $nonMember) {
            /** @var User $user */
            $user = $em->getRepository(User::class)->findOneBy(['email' => $nonMember]);
            if (is_object($user)) {
                $fake_beneficiary = new Beneficiary();
                $fake_beneficiary->setFlying(false);
                $fake_beneficiary->setUser($user);
                $fake_beneficiary->setFirstname($user->getUsername());
                $fake_beneficiary->setLastname(' ');
                $beneficiaries[] = $fake_beneficiary;
            }
        }
        // en non-member

        $from_email = $mailform->get('from')->getData();
        if (in_array($from_email, $mailer_service->getAllowedEmails())) {
            $from_name_and_address = array_search($from_email, $mailer_service->getAllowedEmails());
            $from_name = preg_replace('/<.*>$/', '', $from_name_and_address);
            $from = new Address($from_email, $from_name);
        } else {
            // email not listed !
            return $this->showSendForm($mailform, ['Cet email n\'est pas autorisé : choisis l\'expéditeur dans la liste.']);
        }

        if ('' === trim((string) $mailform->get('subject')->getData())) {
            $errors[] = 'Sujet : ce champ est obligatoire.';
        }
        if ('' === trim((string) $mailform->get('message')->getData())) {
            $errors[] = 'Message : ce champ est obligatoire.';
        }
        if (!$beneficiaries) {
            $errors[] = 'Aucun destinataire : ajoute au moins un membre ou un non-membre.';
        }
        if ($errors) {
            return $this->showSendForm($mailform, $errors);
        }

        // The message is written once for all, personalised for each recipient: check it with the first one.
        try {
            $template = $this->createBodyTemplate((string) $mailform->get('message')->getData(), $mailform->get('template')->getData());
            $this->get('twig')->render($template, ['beneficiary' => reset($beneficiaries)]);
        } catch (TwigError $e) {
            return $this->showSendForm($mailform, ['Le message contient une erreur : ' . $e->getRawMessage() . ($e->getTemplateLine() > 0 ? ' (ligne ' . $e->getTemplateLine() . ')' : '')]);
        }

        $nb = 0;
        $errored = [];
        $sender = $this->getUser() instanceof User ? $this->getUser() : null;
        $batchId = bin2hex(random_bytes(16));
        $queue = $queueStatus->shouldQueue();
        foreach ($beneficiaries as $beneficiary) {
            try {
                $body = $this->get('twig')->render($template, ['beneficiary' => $beneficiary]);
                $message = (new Email())
                    ->subject((string) $mailform->get('subject')->getData())
                    ->from($from)
                    ->to(new Address($beneficiary->getEmail(), $beneficiary->getFirstname() . ' ' . $beneficiary->getLastname()))
                    ->html($body)
                ;
                MailMetadata::written($message, $sender, $batchId);
                if ($queue) {
                    MailMetadata::queue($message);
                }
                $mailer->send($message);
                ++$nb;
            } catch (TransportExceptionInterface|TwigError $exception) {
                $errored[] = $beneficiary->getEmail();
            }
        }

        if (0 === $nb) {
            // Nothing left: keep the message so that it can be sent again.
            return $this->showSendForm($mailform, ['Aucun message n\'a pu être envoyé (serveur d\'envoi injoignable ou adresses refusées) : ' . implode(', ', $errored)]);
        }

        if ($queue) {
            // Flashes are shown as HTML: the link is ours, the number is an integer.
            $this->addFlash('success', sprintf(
                '%d message%s en file d\'envoi : l\'avancement est visible dans l\'<a href="%s" style="color: inherit; text-decoration: underline;">historique des mails</a>.',
                $nb,
                $nb > 1 ? 's' : '',
                $this->generateUrl('admin_sent_email_index')
            ));
            if (!empty($errored)) {
                $this->addFlash('warning', 'Impossible d\'envoyer à : ' . implode(', ', $errored));
            }
        } elseif ($nb > 1) {
            $this->addFlash('success', $nb . ' messages envoyés');
            if (!empty($errored)) {
                $this->addFlash('warning', 'Impossible d\'envoyer à : ' . implode(', ', $errored));
            }
        } else {
            $this->addFlash('success', 'message envoyé');
        }

        return $this->redirectToRoute('mail_edit');
    }

    /**
     * The error of the form, in words for the person who wrote the message.
     */
    private function describeFormError(FormError $error): string
    {
        $field = $error->getOrigin();
        if ($field->isRoot()) {
            return 'Le formulaire a expiré : envoie-le de nouveau (ton message est conservé).';
        }

        switch ($field->getName()) {
            case 'from':
                return 'Cet email n\'est pas autorisé : choisis l\'expéditeur dans la liste.';

            case 'subject':
                return 'Sujet : ce champ est obligatoire.';

            case 'message':
                return 'Message : ce champ est obligatoire.';
        }
        if (is_numeric($field->getName())) {
            return 'Un destinataire n\'est pas reconnu : choisis-le dans la liste proposée.';
        }

        $label = $field->getConfig()->getOption('label');

        return ($label ? $label . ' : ' : '') . $error->getMessage();
    }

    /**
     * The page of the form with what was typed and the reasons why the message
     * was not sent.
     *
     * @param FormInterface<mixed> $mailform
     * @param string[]             $errors
     */
    private function showSendForm(FormInterface $mailform, array $errors): Response
    {
        return $this->render('admin/mail/send.html.twig', [
            'form' => $mailform->createView(),
            'non_members' => $this->getNonMemberEmails(),
            'errors' => $errors,
        ]);
    }

    /**
     * The body of the message: Markdown, in the chosen template, then Twig
     * ({{ beneficiary.firstname }}...).
     *
     * @param null|EmailTemplate $emailTemplate
     *
     * @throws TwigError when the message is not valid Twig
     */
    private function createBodyTemplate(string $message, $emailTemplate): TemplateWrapper
    {
        $parser = new Markdown();
        $parser->hard_wrap = true;
        $content = $parser->transform($message);
        if ($emailTemplate) {
            $content = str_replace('{{template_content}}', $content, $emailTemplate->getContent());
        }

        return $this->get('twig')->createTemplate($content);
    }

    private function getMailForm(MailerService $mailer_service, $to = [])
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('mail_send'))
            ->setMethod('POST')
            ->add('from', ChoiceType::class, [
                'label' => 'Depuis',
                'required' => false,
                'choices' => $mailer_service->getAllowedEmails(),
            ])
            ->add('to', AutocompleteBeneficiaryCollectionType::class, [
                'data' => $to,
                'label' => 'Destinataire(s)',
            ])
            ->add('cci', HiddenType::class, ['label' => 'Non-membres', 'required' => false])
            ->add('template', EntityType::class, [
                'class' => 'App:EmailTemplate',
                'placeholder' => '',
                'choice_label' => 'name',
                'multiple' => false,
                'required' => false,
                'label' => 'Modèle',
            ])
            ->add('subject', TextType::class, ['label' => 'Sujet', 'required' => true])
            ->add('message', MarkdownEditorType::class, ['label' => 'Message', 'required' => true, 'attr' => ['class' => 'materialize-textarea']])
            ->getForm()
        ;
    }

    private function getNonMemberEmails()
    {
        $em = $this->getDoctrine()->getManager();
        $non_members = $em->getRepository(User::class)->findNonMembers(true);
        $list = [];
        foreach ($non_members as $non_member) {
            $list[$non_member->getEmail()] = '';
        }

        return $list;
    }
}
