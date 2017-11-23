<?php

namespace craftcom\controllers;

use Craft;
use craft\base\Element;
use craft\elements\Asset;
use craft\elements\Category;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\web\Controller;
use craft\web\UploadedFile;
use craftcom\Module;
use craftcom\plugins\Plugin;
use Github\Api\Repo;
use Github\Client;
use Github\Exception\RuntimeException;
use yii\base\Exception;
use yii\helpers\Inflector;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * @property Module $module
 */
class PluginsController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        return true;
    }

    /**
     * @param string $repository
     *
     * @return Response
     */
    public function actionLoadDetails(string $repository): Response
    {
        $this->requireAcceptsJson();
        $parsed = parse_url($repository);
        $uriParts = isset($parsed['path']) ? explode('/', trim($parsed['path'], '/'), 4) : [];
        list($owner, $repo, $route, $ref) = array_pad($uriParts, 4, null);

        // Make sure this looks like a GitHub repo
        if (
            !isset($parsed['host']) ||
            $parsed['host'] !== 'github.com' ||
            $owner === null ||
            $repo === null ||
            ($route !== null && ($route !== 'tree' || $ref === null))
        ) {
            return $this->asErrorJson("{$repository} is not a valid GitHub repository URL");
        }

        $client = new Client();

        if ($token = Module::getInstance()->getPackageManager()->getRandomGitHubFallbackToken()) {
            $client->authenticate($token, null, Client::AUTH_HTTP_TOKEN);
        }

        /** @var Repo $api */
        $api = $client->api('repo');

        // Get the composer.json contents
        try {
            $response = $api->contents()->show($owner, $repo, 'composer.json', $ref);
            $config = Json::decode(base64_decode($response['content']));
        } catch (\Throwable $e) {
            return $this->asErrorJson($e->getMessage());
        }

        // Get the title and handle
        $handle = $config['extra']['handle'] ?? null;
        if (strtolower($handle) !== $handle) {
            $handle = preg_replace('/\-{2,}/', '-', Inflector::camel2id($handle));
        }
        $name = $config['extra']['name'] ?? null;

        // Get the license
        if (isset($config['license']) && strtolower($config['license']) === 'mit') {
            $license = 'mit';
        } else {
            $license = 'craft';
        }

        // Get the icon, if we have one
        if ($icon = $this->_getIcon($api, $owner, $repo, $ref, $config, $handle, $name)) {
            if (Craft::$app->getRequest()->getIsCpRequest()) {
                $iconHtml = Craft::$app->getView()->renderTemplate('_elements/element', [
                    'element' => $icon
                ]);
            } else {
                $iconHtml = null;
                $iconId = $icon->id;
                $iconUrl = $icon->getUrl();
            }
        } else {
            $iconHtml = null;
        }

        // Get the changelog path
        if (isset($config['extra']['changelogUrl'])) {
            $changelogPath = basename($config['extra']['changelogUrl']);
        } else {
            $changelogPath = null;
        }

        return $this->asJson([
            'repository' => "https://github.com/{$owner}/{$repo}",
            'name' => $name,
            'packageName' => $config['name'] ?? null,
            'handle' => $handle,
            'license' => $license,
            'shortDescription' => $config['extra']['description'] ?? $config['description'] ?? null,
            'documentationUrl' => $config['extra']['documentationUrl'] ?? $config['support']['docs'] ?? null,
            'changelogPath' => $changelogPath,
            'icon' => $iconHtml,
            'iconId' => (isset($iconId) ? $iconId : null),
            'iconUrl' => (isset($iconUrl) ? $iconUrl : null),
        ]);
    }

    /**
     * @param int|null    $pluginId
     * @param Plugin|null $plugin
     *
     * @return Response
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     */
    public function actionEdit(int $pluginId = null, Plugin $plugin = null): Response
    {
        if ($plugin === null) {
            if ($pluginId !== null) {
                $plugin = Plugin::find()->id($pluginId)->status(null)->one();
                if ($plugin === null) {
                    throw new NotFoundHttpException('Invalid plugin ID: '.$pluginId);
                }

                if (!Craft::$app->getUser()->checkPermission('craftcom:managePlugins') && Craft::$app->getUser()->getId() !== $plugin->developerId) {
                    throw new ForbiddenHttpException('User is not permitted to perform this action');
                }
            } else {
                $plugin = new Plugin([
                    'enabled' => false,
                    'categories' => [],
                    'screenshots' => [],
                ]);
            }
        }

        $title = $plugin->id ? $plugin->name : 'Add a new plugin';

        return $this->renderTemplate('craftcom/plugins/_edit', compact('plugin', 'title'));
    }

    /**
     * @return Response
     * @throws Exception
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     */
    public function actionSave()
    {
        $request = Craft::$app->getRequest();

        if ($pluginId = $request->getBodyParam('pluginId')) {
            $plugin = Plugin::find()->id($pluginId)->status(null)->one();
            if ($plugin === null) {
                throw new NotFoundHttpException('Invalid plugin ID: '.$pluginId);
            }

            if (!Craft::$app->getUser()->checkPermission('craftcom:managePlugins') && Craft::$app->getUser()->getId() !== $plugin->developerId) {
                throw new ForbiddenHttpException('User is not permitted to perform this action');
            }
        } else {
            $plugin = new Plugin();
        }

        if ($request->getIsCpRequest()) {
            $plugin->enabled = (bool)$request->getBodyParam('enabled');
        }

        if (!$plugin->developerId) {
            $plugin->developerId = Craft::$app->getUser()->getId();
        }

        // Only plugin managers are able to change developer for a plugin
        if (Craft::$app->getUser()->checkPermission('craftcom:managePlugins') && isset($request->getBodyParam('developerId')[0])) {
            $plugin->developerId = $request->getBodyParam('developerId')[0];
        }

        $newName = false;
        $newHandle = false;

        if ($plugin->name != $request->getBodyParam('name')) {
            $newName = true;
        }

        $plugin->name = $request->getBodyParam('name');

        if ($plugin->handle != $request->getBodyParam('handle')) {
            $newHandle = true;
        }

        $plugin->handle = $request->getBodyParam('handle');

        $plugin->iconId = $request->getBodyParam('iconId')[0] ?? null;
        $plugin->packageName = $request->getBodyParam('packageName');
        $plugin->repository = $request->getBodyParam('repository');
        $plugin->price = (float)$request->getBodyParam('price');
        $plugin->renewalPrice = (float)$request->getBodyParam('renewalPrice');
        $plugin->license = $request->getBodyParam('license');
        $plugin->shortDescription = $request->getBodyParam('shortDescription');
        $plugin->longDescription = $request->getBodyParam('longDescription');
        $plugin->documentationUrl = $request->getBodyParam('documentationUrl');
        $plugin->changelogPath = $request->getBodyParam('changelogPath') ?: null;

        $plugin->setCategories(Category::find()->id($request->getBodyParam('categoryIds'))->fixedOrder()->all());


        // Uploads

        $assetsService = Craft::$app->getAssets();
        $volumesService = Craft::$app->getVolumes();

        $screenshotIds = (!empty($request->getBodyParam('screenshotIds')) ? $request->getBodyParam('screenshotIds') : []);

        if (!$request->getIsCpRequest()) {

            // Icon

            $iconFile = UploadedFile::getInstanceByName('icon');

            if ($iconFile) {
                $name = $plugin->name;
                $handle = $plugin->handle;
                $tempPath = Craft::$app->getPath()->getTempPath()."/icon-{$handle}-".StringHelper::randomString().'.svg';
                move_uploaded_file($iconFile->tempName, $tempPath);


                // Save as an asset
                $volume = $volumesService->getVolumeByHandle('icons');
                $folderId = $volumesService->ensureTopFolder($volume);

                $targetFilename = "{$handle}.svg";

                $assetToReplace = Asset::find()
                    ->folderId($folderId)
                    ->filename(Db::escapeParam($targetFilename))
                    ->one();

                if ($assetToReplace) {
                    Craft::$app->getAssets()->replaceAssetFile($assetToReplace, $tempPath, $assetToReplace->filename);
                    $plugin->iconId = $assetToReplace->id;
                } else {
                    $icon = new Asset([
                        'title' => $name,
                        'tempFilePath' => $tempPath,
                        'newLocation' => "{folder:{$folderId}}{$handle}.svg",
                    ]);

                    if (!Craft::$app->getElements()->saveElement($icon, false)) {
                        throw new Exception('Unable to save icon asset: '.implode(',', $icon->getFirstErrors()));
                    }

                    $plugin->iconId = $icon->id;
                }
            }


            // Screenshots

            // Remove old screenshots

            $existingScreenshots = $plugin->getScreenshots();

            foreach ($existingScreenshots as $existingScreenshot) {
                $remove = true;
                foreach ($screenshotIds as $screenshotId) {
                    if ($existingScreenshot->id == $screenshotId) {
                        $remove = false;
                    }
                }

                if ($remove) {
                    Craft::$app->getElements()->deleteElementById($existingScreenshot->id, Asset::class);
                }
            }


            // Upload new screenshots

            $screenshotFiles = UploadedFile::getInstancesByName('screenshots');

            if (count($screenshotFiles) > 0) {
                foreach ($screenshotFiles as $screenshotFile) {
                    $handle = $plugin->handle;
                    $tempPath = Craft::$app->getPath()->getTempPath()."/screenshot-{$handle}-".StringHelper::randomString().'.'.$screenshotFile->getExtension();
                    move_uploaded_file($screenshotFile->tempName, $tempPath);


                    // Save as an asset
                    $volumesService = Craft::$app->getVolumes();
                    $volume = $volumesService->getVolumeByHandle('screenshots');
                    $volumeId = $volumesService->ensureTopFolder($volume);

                    $subpath = '/'.$handle;

                    $folder = $assetsService->findFolder([
                        'volumeId' => $volumeId,
                        'path' => $subpath.'/'
                    ]);

                    if (!$folder) {
                        $folderId = $assetsService->ensureFolderByFullPathAndVolume($subpath, $volume);
                    } else {
                        $folderId = $folder->id;
                    }

                    $targetFilename = $screenshotFile->name;

                    $screenshot = new Asset([
                        'title' => $plugin->name,
                        'tempFilePath' => $tempPath,
                        'newLocation' => "{folder:{$folderId}}".$targetFilename,
                        'avoidFilenameConflicts' => true,
                    ]);

                    $screenshot->validate(['newLocation']);

                    if ($screenshot->hasErrors() || !Craft::$app->getElements()->saveElement($screenshot, false)) {
                        throw new Exception('Unable to save icon asset: '.implode(',', $screenshot->getFirstErrors()));
                    }

                    $screenshotIds[] = $screenshot->id;
                }
            }
        }

        $plugin->setScreenshots(Asset::find()->id($screenshotIds)->fixedOrder()->all());


        // Save plugin

        if ($plugin->enabled) {
            $plugin->setScenario(Element::SCENARIO_LIVE);
        }

        if (!Craft::$app->getElements()->saveElement($plugin)) {
            if ($request->getAcceptsJson()) {
                return $this->asJson([
                    'errors' => $plugin->getErrors(),
                ]);
            }

            Craft::$app->getSession()->setError('Couldn’t save plugin.');
            Craft::$app->getUrlManager()->setRouteParams([
                'plugin' => $plugin
            ]);
            return null;
        }


        // Rename icon with new name and filename

        if ($newName || $newHandle) {

            // Icon

            if ($plugin->icon) {
                $icon = $plugin->icon;

                if ($newName) {
                    $icon->title = $plugin->name;
                }

                if ($newHandle) {
                    $icon->newFilename = $plugin->handle.'.'.$icon->getExtension();
                }

                if (!Craft::$app->getElements()->saveElement($icon, false)) {
                    throw new Exception('Unable to save icon asset: '.implode(',', $icon->getFirstErrors()));
                }
            }


            // Screenshots

            $volume = $volumesService->getVolumeByHandle('screenshots');
            $volumeId = $volumesService->ensureTopFolder($volume);

            $subpath = '/'.$plugin->handle;

            $folder = $assetsService->findFolder([
                'volumeId' => $volumeId,
                'path' => $subpath.'/'
            ]);

            if (!$folder) {
                $folderId = $assetsService->ensureFolderByFullPathAndVolume($subpath, $volume);
                $folder = $assetsService->getFolderById($folderId);
            }

            foreach ($plugin->screenshots as $screenshot) {
                if (!$assetsService->moveAsset($screenshot, $folder)) {
                    throw new Exception('Unable to save icon asset: '.implode(',', $screenshot->getFirstErrors()));
                }
            }
        }

        // Now add our webhook if this is a new plugin
        if (!$pluginId) {
            $this->module->getPackageManager()->createWebhook($plugin->packageName);
        }

        if ($request->getAcceptsJson()) {
            $return = [];

            $return['success'] = true;
            $return['id'] = $plugin->id;
            $return['iconId'] = $plugin->icon->id;
            $return['iconUrl'] = $plugin->icon->getUrl();
            $return['name'] = $plugin->name;

            $return['screenshots'] = [];

            foreach ($plugin->getScreenshots() as $screenshot) {
                $return['screenshots'][] = [
                    'id' => $screenshot->id,
                    'url' => $screenshot->getUrl(),
                ];
            }

            return $this->asJson($return);
        }

        Craft::$app->getSession()->setNotice('Plugin saved.');

        return $this->redirectToPostedUrl($plugin);
    }

    /**
     * @return null|Response
     * @throws NotFoundHttpException
     */
    public function actionDelete()
    {
        $request = Craft::$app->getRequest();
        $pluginId = $request->getBodyParam('pluginId');
        $plugin = Plugin::find()->id($pluginId)->status(null)->one();

        if (!$plugin) {
            throw new NotFoundHttpException('Plugin not found');
        }


        // Delete icon

        if ($plugin->icon) {
            Craft::$app->getElements()->deleteElement($plugin->icon);
        }


        // Delete screenshots

        foreach ($plugin->screenshots as $screenshot) {
            Craft::$app->getElements()->deleteElement($screenshot);
        }


        // Delete plugin

        if (!Craft::$app->getElements()->deleteElement($plugin)) {
            Craft::$app->getSession()->setError('Couldn’t delete plugin.');
            return null;
        }

        Craft::$app->getSession()->setNotice('Plugin deleted.');
        return $this->redirectToPostedUrl($plugin);
    }

    /**
     * Submits a plugin for approval.
     *
     * @return Response
     * @throws NotFoundHttpException
     */
    public function actionSubmit(): Response
    {
        $request = Craft::$app->getRequest();
        $pluginId = $request->getBodyParam('pluginId');
        $plugin = Plugin::find()->id($pluginId)->status(null)->one();

        if (!$plugin) {
            throw new NotFoundHttpException('Plugin not found');
        }

        $plugin->pendingApproval = true;


        // Save plugin

        if (!Craft::$app->getElements()->saveElement($plugin)) {
            if ($request->getAcceptsJson()) {
                return $this->asJson([
                    'errors' => $plugin->getErrors(),
                ]);
            }

            Craft::$app->getSession()->setError('Couldn’t submit plugin for approval.');
            Craft::$app->getUrlManager()->setRouteParams([
                'plugin' => $plugin
            ]);
            return null;
        }

        try
        {
            Craft::$app->getMailer()->compose()
                ->setSubject('A plugin is waiting for approval: '.$plugin->name)
                ->setTextBody('https://id.craftcms.com/'.getenv('CRAFT_CP_TRIGGER').'/plugins/'.$plugin->id)
                ->setTo(explode(',', getenv('PLUGIN_APPROVAL_RECIPIENTS')))
                ->send();
        }
        catch (\Exception $e)
        {
            // Just log and move on.
            Craft::error('There was a problem sending the plugin approval email: '.$e->getMessage(), __METHOD__);
        }

        // Return

        if ($request->getAcceptsJson()) {
            $return = [];
            $return['success'] = true;

            return $this->asJson($return);
        }

        Craft::$app->getSession()->setNotice('Plugin submitted for approval.');
        return $this->redirectToPostedUrl($plugin);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns a plugin’s README contents
     *
     * @param Repo   $api
     * @param string $owner
     * @param string $repo
     *
     * @return string|null
     */
    private function _getReadme(Repo $api, string $owner, string $repo)
    {
        try {
            return $api->readme($owner, $repo);
        } catch (RuntimeException $e) {
            return null;
        }
    }

    /**
     * Returns a plugin’s icon.
     *
     * @param Repo        $api
     * @param string      $owner
     * @param string      $repo
     * @param string|null $ref
     * @param array       $config
     * @param string|null $handle
     * @param string|null $name
     *
     * @return Asset|null
     */
    private function _getIcon(Repo $api, string $owner, string $repo, string $ref = null, array $config, string $handle = null, string $name = null)
    {
        // Make sure the plugin has a handle
        if (!$handle) {
            return null;
        }

        // Kebab-case it
        if (strtolower($handle) !== $handle) {
            $handle = preg_replace('/\-{2,}/', '-', Inflector::camel2id($handle));
        }

        // See if we already happen to have an icon for this handle
        $filename = $handle.'.svg';
        if ($icon = Asset::find()->volume('icons')->filename($filename)->one()) {
            return $icon;
        }

        // Make sure there are some autoload paths
        if (!isset($config['autoload']['psr-4'])) {
            return null;
        }

        // Loop through the autoload paths and look for icons
        $basePath = isset($config['extra']['basePath']) && strpos($config['extra']['basePath'], '@') === 0 ? rtrim($config['extra']['basePath']) : null;
        $pluginClass = isset($config['extra']['class']) ? ltrim($config['extra']['class'], '\\') : null;

        foreach ($config['autoload']['psr-4'] as $namespace => $path) {
            $namespace = trim($namespace, '\\');
            $path = rtrim($path, '/');

            // If basePath is defined, we only care about namespace path(s) that include it
            if ($basePath !== null) {
                $alias = '@'.str_replace('\\', '/', $namespace);
                if (strpos($basePath.'/', $alias.'/') === 0) {
                    $testPath = $path.substr($config['extra']['basePath'], strlen($alias));
                    if ($icon = $this->_getIconInPath($api, $owner, $repo, $ref, $handle, $name, $testPath)) {
                        return $icon;
                    };
                }
            } // If the plugin class is defined, we only care about namespace path(s) that include its directory
            else if ($pluginClass !== null) {
                if (strpos($pluginClass, $namespace.'\\') === 0) {
                    $subPath = str_replace('\\', '/', substr($pluginClass, strlen($namespace) + 1));
                    $testPath = $path.(dirname($subPath) !== '.' ? '/'.dirname($subPath) : '');
                    if ($icon = $this->_getIconInPath($api, $owner, $repo, $ref, $handle, $name, $testPath)) {
                        return $icon;
                    };
                }
            } else {
                // Only include all autoload paths if basePath and pluginClass are not set
                if ($icon = $this->_getIconInPath($api, $owner, $repo, $ref, $handle, $name, $path)) {
                    return $icon;
                };
            }
        }

        return null;
    }

    /**
     * Looks for a plugin’s icon within a specific path
     *
     * @param Repo        $api
     * @param string      $owner
     * @param string      $repo
     * @param string|null $ref
     * @param string      $handle
     * @param string|null $name
     *
     * @param string      $testPath
     *
     * @return Asset|null
     * @throws Exception if the icon asset can't be saved
     */
    private function _getIconInPath(Repo $api, string $owner, string $repo, string $ref = null, string $handle, string $name = null, string $testPath)
    {
        try {
            $response = $api->contents()->show($owner, $repo, $testPath.'/icon.svg', $ref);
        } catch (RuntimeException $e) {
            return null;
        }

        // Decode and save it
        $contents = base64_decode($response['content']);
        $tempPath = Craft::$app->getPath()->getTempPath()."/icon-{$handle}-".StringHelper::randomString().'.svg';
        FileHelper::writeToFile($tempPath, $contents);

        // Save as an asset
        $volumesService = Craft::$app->getVolumes();
        $volume = $volumesService->getVolumeByHandle('icons');
        $folderId = $volumesService->ensureTopFolder($volume);

        $icon = new Asset([
            'title' => $name,
            'tempFilePath' => $tempPath,
            'newLocation' => "{folder:{$folderId}}{$handle}".StringHelper::randomString().".svg",
        ]);

        if (!Craft::$app->getElements()->saveElement($icon, false)) {
            throw new Exception('Unable to save icon asset: '.implode(',', $icon->getFirstErrors()));
        }

        return $icon;
    }
}
