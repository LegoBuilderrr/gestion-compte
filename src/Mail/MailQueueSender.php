<?php

namespace App\Mail;

use App\Entity\EmailLog;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Sends the mails waiting in the history (status "queued"), oldest first.
 *
 * It talks to the mail server directly (not through the RecordingMailer): the
 * line of the history already exists, it only gets its final status. A mail
 * the server refuses is marked "failed" and does not stop the others.
 *
 * Only one sender works at a time (database lock): a run that is still going
 * when the next one starts is not doubled.
 */
class MailQueueSender
{
    private const LOCK = 'app_mail_queue';

    /** @var MailerInterface */
    private $mailer;

    /** @var Connection */
    private $connection;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(MailerInterface $mailer, Connection $connection, LoggerInterface $logger)
    {
        $this->mailer = $mailer;
        $this->connection = $connection;
        $this->logger = $logger;
    }

    /**
     * @param int $limit      maximum number of mails handed to the server
     * @param int $maxSeconds stop taking new mails after this time (0: no limit)
     *
     * @return null|array{sent: int, failed: int, remaining: int} null when another sender is already working
     */
    public function send(int $limit = 500, int $maxSeconds = 50): ?array
    {
        if (!$this->lock()) {
            return null;
        }

        try {
            return $this->run($limit, $maxSeconds);
        } finally {
            $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }

    public function countQueued(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(id) FROM email_log WHERE status = ?', [EmailLog::STATUS_QUEUED]);
    }

    private function lock(): bool
    {
        return 1 === (int) $this->connection->fetchOne('SELECT GET_LOCK(?, 0)', [self::LOCK]);
    }

    /**
     * @return array{sent: int, failed: int, remaining: int}
     */
    private function run(int $limit, int $maxSeconds): array
    {
        $start = time();
        $sent = 0;
        $failed = 0;

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, queued_message FROM email_log WHERE status = ? ORDER BY id ASC LIMIT ' . max(1, $limit),
            [EmailLog::STATUS_QUEUED]
        );

        foreach ($rows as $row) {
            if ($maxSeconds > 0 && time() - $start >= $maxSeconds) {
                break;
            }

            try {
                $this->mailer->send(QueuedMessage::decode((string) $row['queued_message']));
                $this->close((int) $row['id'], EmailLog::STATUS_SENT, null);
                ++$sent;
            } catch (\Throwable $e) {
                $this->close((int) $row['id'], EmailLog::STATUS_FAILED, $e->getMessage());
                ++$failed;
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'remaining' => $this->countQueued()];
    }

    private function close(int $id, string $status, ?string $error): void
    {
        try {
            $this->connection->update('email_log', [
                'status' => $status,
                'error' => $error,
                'queued_message' => null,
            ], ['id' => $id]);
        } catch (\Throwable $e) {
            $this->logger->error('Mail queue: the status of the mail ' . $id . ' could not be updated: ' . $e->getMessage());
        }
    }
}
