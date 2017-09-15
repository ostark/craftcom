<?php

namespace craftcom\api\controllers\v1;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craftcom\api\controllers\BaseApiController;
use yii\web\Response;

/**
 * Class DeveloperController
 *
 * @package craftcom\api\controllers\v1
 */
class DeveloperController extends BaseApiController
{
    // Public Methods
    // =========================================================================

    /**
     * Handles /v1/developer/<userId> requests.
     *
     * @return Response
     */
    public function actionIndex($userId): Response
    {
        $user = User::find()->id($userId)->one();

        if($user) {
            $plugins = [];
            $entries = Entry::find()->section('plugins')->authorId($user->id)->orderBy('title asc')->all();

            foreach($entries as $entry) {
                $plugins[] = $this->pluginTransformer($entry);
            }

            return $this->asJson([
                'developerName' => $user->developerName,
                'developerUrl' => $user->developerUrl,
                'location' => $user->location,
                'username' => $user->username,
                'fullName' => $user->getFullName(),
                'email' => $user->email,
                'plugins' => $plugins,
            ]);
        }

        return $this->asErrorJson("Couldn’t find developer");
    }
}