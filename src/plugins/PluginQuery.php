<?php

namespace craftcom\plugins;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use yii\db\Connection;

/**
 * @method Plugin[]|array all($db = null)
 * @method Plugin|array|null one($db = null)
 * @method Plugin|array|null nth(int $n, Connection $db = null)
 */
class PluginQuery extends ElementQuery
{
    /**
     * @var string|string[]|null The handle(s) that the resulting plugins must have.
     */
    public $handle;

    /**
     * @var int|int[]|null The user ID(s) that the resulting plugins’ developers must have.
     */
    public $developerId;

    /**
     * @var int|int[]|null The Composer package ID(s) that the resulting plugins must be associated with.
     */
    public $packageId;

    public function __construct($elementType, array $config = [])
    {
        // Default orderBy
        if (!isset($config['orderBy'])) {
            $config['orderBy'] = 'name';
        }

        parent::__construct($elementType, $config);
    }

    /**
     * Sets the [[handle]] property.
     *
     * @param string|string[]|null $value The property value
     *
     * @return static self reference
     */
    public function handle($value)
    {
        $this->handle = $value;
        return $this;
    }

    /**
     * Sets the [[developerId]] property.
     *
     * @param int|int[]|null $value The property value
     *
     * @return static self reference
     */
    public function developerId($value)
    {
        $this->developerId = $value;

        return $this;
    }

    /**
     * Sets the [[packageId]] property.
     *
     * @param int|int[]|null $value The property value
     *
     * @return static self reference
     */
    public function packageId($value)
    {
        $this->packageId = $value;

        return $this;
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('craftcom_plugins');

        $this->query->select([
            'craftcom_plugins.developerId',
            'craftcom_plugins.packageId',
            'craftcom_plugins.iconId',
            'craftcom_plugins.packageName',
            'craftcom_plugins.repository',
            'craftcom_plugins.name',
            'craftcom_plugins.handle',
            'craftcom_plugins.price',
            'craftcom_plugins.renewalPrice',
            'craftcom_plugins.license',
            'craftcom_plugins.shortDescription',
            'craftcom_plugins.longDescription',
            'craftcom_plugins.documentationUrl',
            'craftcom_plugins.changelogPath',
            'craftcom_plugins.latestVersion',
            'craftcom_plugins.pendingApproval',
        ]);

        if ($this->handle) {
            $this->subQuery->andWhere(Db::parseParam('craftcom_plugins.handle', $this->handle));
        }

        if ($this->developerId) {
            $this->subQuery->andWhere(Db::parseParam('craftcom_plugins.developerId', $this->developerId));
        }

        if ($this->packageId) {
            $this->subQuery->andWhere(Db::parseParam('craftcom_plugins.packageId', $this->packageId));
        }

        return parent::beforePrepare();
    }

    /**
     * @inheritdoc
     */
    protected function statusCondition(string $status)
    {
        if ($status === Plugin::STATUS_PENDING) {
            return ['elements.enabled' => false, 'craftcom_plugins.pendingApproval' => true];
        }

        return parent::statusCondition($status);
    }
}
