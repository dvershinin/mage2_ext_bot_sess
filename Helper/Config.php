<?php
/**
 * User: Alex Gusev <alex@flancer64.com>
 */

namespace Flancer32\BotSess\Helper;

/**
 * Helper to get store configuration parameters related to the module.
 *
 * (see ./src/etc/adminhtml/system.xml)
 */
class Config
{

    const DEF_BOT_CLEANUP_DELTA = 3600;

    /** @var \Magento\Framework\App\Config\ScopeConfigInterface */
    private $scopeConfig;

    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Converts multiline configuration (^alexa\n^blitz\.io\n...yandex)
     * into regex "/^alexa|^blitz\.io|...|yandex|/i" to filter bots.
     *
     * @return string
     */
    public function getFilter()
    {
        $lines = $this->scopeConfig->getValue('web/session_bots/filter');
        $exploded = explode(PHP_EOL, $lines);
        $result = '/';
        foreach ($exploded as $item) {
            $one = trim($item);
            if ($one) $result .= $one . '|';
        }
        $len = strlen($result);
        $result = substr($result, 0, $len - 1);
        $result .= '/i';
        return $result;
    }
    /**
     * Max lifetime for bots sessions (in sec.) before cleanup.
     *
     * @return bool
     */
    public function getBotsCleanupDelta()
    {
        $result = $this->scopeConfig->getValue('web/session/bots_cleanup_delta');
        $result = filter_var($result, FILTER_VALIDATE_INT);
        if ($result <= 0) {
            $result = self::DEF_BOT_CLEANUP_DELTA;
        }
        return $result;
    }

}