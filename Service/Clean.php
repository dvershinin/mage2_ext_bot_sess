<?php
/**
 * Authors: Alex Gusev <alex@flancer64.com>
 * Since: 2018
 */

namespace Flancer32\BotSess\Service;

use Flancer32\BotSess\Config as Cfg;
use Flancer32\BotSess\Helper\Session as HSession;
use Flancer32\BotSess\Service\Clean\A\Request as ARequest;
use Flancer32\BotSess\Service\Clean\A\Response as AResponse;

/**
 * Clean up user's expired sessions and all bot's sessions.
 */
class Clean
{
    /** Process DB sessions with batches to prevent freeze for other connections */
    const BATCH_LIMIT = 1000;

    /** @var \Magento\Framework\DB\Adapter\AdapterInterface */
    private $conn;
    /** @var \Flancer32\BotSess\Helper\Config */
    private $hlpCfg;
    /** @var \Flancer32\BotSess\Helper\Filter */
    private $hlpFilter;
    /** @var \Flancer32\BotSess\Helper\Session */
    private $hlpSession;
    /** @var \Flancer32\BotSess\Logger */
    private $logger;
    /** @var \Magento\Framework\App\ResourceConnection */
    private $resource;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resource,
        \Flancer32\BotSess\Logger $logger,
        \Flancer32\BotSess\Helper\Config $hlpCfg,
        \Flancer32\BotSess\Helper\Filter $hlpFilter,
        \Flancer32\BotSess\Helper\Session $hlpSession
    ) {
        $this->resource = $resource;
        $this->conn = $resource->getConnection();
        $this->logger = $logger;
        $this->hlpCfg = $hlpCfg;
        $this->hlpFilter = $hlpFilter;
        $this->hlpSession = $hlpSession;
    }

    /**
     * Analyze session data and perform clean up if required.
     *
     * @param string $sessId
     * @param array $sessData
     * @param int $now
     * @param int $created
     * @param int $sessLifetime
     * @param AResponse $response
     */
    private function analyzeSession($sessId, $sessData, $now, $created, $sessLifetime, $response)
    {
        if (isset($sessData['_session_validator_data']['http_user_agent'])) {
            $agent = $sessData['_session_validator_data']['http_user_agent'];
            $isBot = $this->hlpFilter->isBot($agent);
            if ($isBot) {
                /* remove all bots sessions w/o lifetime excuses */
                $this->logger->debug("Session '$sessId' belongs to bot ($agent).");
                $deleted = $this->deleteSession($sessId);
                if ($deleted == 1) {
                    $this->logger->debug("Session '$sessId' is deleted as bot.");
                    $response->removedBots++;
                } else {
                    $this->logger->error("Cannot delete bot session '$sessId'.");
                    $response->failures++;
                }
            } else {
                /* human session, save agents used by humans */
                if (isset($response->agents[$agent])) {
                    $response->agents[$agent]++;
                } else {
                    $response->agents[$agent] = 1;
                }
                /* analyze session data and remove expired sessions of users */
                $delta = $now - $created;
                if ($delta > $sessLifetime) {
                    /* session age is over then time delta for inactive user, remove the session */
                    $this->logger->debug("Session '$sessId' belongs to inactive user.");
                    $deleted = $this->deleteSession($sessId);
                    if ($deleted == 1) {
                        $this->logger->debug("Session '$sessId' is deleted as inactive.");
                        $response->removedInactive++;
                    } else {
                        $this->logger->error("Cannot delete inactive session '$sessId'.");
                        $response->failures++;
                    }
                } else {
                    $response->active++;
                }

            }
        } else {
            /* this is not Magento session */
            $response->failures++;
        }
    }

    /**
     * Delete session by ID.
     *
     * @param string $id
     * @return int
     */
    private function deleteSession($id)
    {
        $tbl = $this->resource->getTableName(Cfg::ENTITY_SESSION);
        $where = 'session_id=' . $this->conn->quote($id);
        $result = $this->conn->delete($tbl, $where);
        return $result;
    }

    /**
     * @param ARequest $request
     * @return AResponse
     */
    public function exec($request)
    {
        assert($request instanceof ARequest);
        /** define local working data */
        $result = new AResponse();
        $total = $this->getSessionsCount();
        $current = 0;
        $limit = self::BATCH_LIMIT;
        $id = null;
        $now = time();          // current time
        $sessLifetime = $this->hlpCfg->getCookieLifetime();

        /** perform processing */
        /* external loop to process all sessions by batches */
        while ($current < $total) {
            /* get one batch sessions then process its in loop */
            $batch = $this->getSessionsBatch($id, $limit);
            foreach ($batch as $one) {
                $current++;
                $id = $one['session_id'];
                $created = $one['session_expires']; // session creation time (???)
                $data = $one['session_data'];
                $decoded = base64_decode($data);
                try {
                    $session = $this->hlpSession->decode($decoded);
                    $this->analyzeSession($id, $session, $now, $created, $sessLifetime, $result);
                } catch (\Throwable $e) {
                    $this->logger->err("Session '$id':" . $e->getMessage());
                    $result->failures++;
                }
            }
        }

        /** compose result */
        $result->total = $total;
        return $result;
    }

    /**
     * Get sessions from DB where 'session_id' greater then given $id.
     *
     * @param string $id
     * @param int $limit
     * @return array
     */
    private function getSessionsBatch($id, $limit)
    {
        $tbl = $this->resource->getTableName(Cfg::ENTITY_SESSION);
        $select = $this->conn->select()->from($tbl);
        if (!is_null($id)) {
            $where = "session_id>" . $this->conn->quote($id);
            $select->where($where);
        }
        $select->limit($limit);
        $result = $this->conn->fetchAll($select);
        return $result;
    }

    /**
     * Get total count of the all sessions from DB.
     *
     * @return int
     */
    private function getSessionsCount()
    {
        $tbl = $this->resource->getTableName(Cfg::ENTITY_SESSION);
        $select = $this->conn->select();
        $select->from($tbl, 'COUNT(session_id)');
        $result = $this->conn->fetchOne($select);
        return $result;
    }
}