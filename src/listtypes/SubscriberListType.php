<?php

namespace barrelstrength\sproutlists\listtypes;

use barrelstrength\sproutbase\app\lists\base\ListType;
use barrelstrength\sproutlists\elements\Lists;
use barrelstrength\sproutlists\elements\Subscribers;
use barrelstrength\sproutlists\models\Settings;
use barrelstrength\sproutlists\models\Subscription;
use barrelstrength\sproutlists\records\Subscription as SubscriptionRecord;
use barrelstrength\sproutlists\SproutLists;
use Craft;
use craft\helpers\Template;
use barrelstrength\sproutlists\records\Subscribers as SubscribersRecord;
use barrelstrength\sproutlists\records\Lists as ListsRecord;
use yii\base\Exception;

class SubscriberListType extends ListType
{
    /**
     * @return string
     */
    public function getName()
    {
        return Craft::t('sprout-lists', 'Subscriber Lists');
    }

    // Lists
    // =========================================================================

    /**
     * Saves a list.
     *
     * @param Lists $list
     *
     * @return bool|mixed
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \yii\base\Exception
     */
    public function saveList(Lists $list)
    {
        $list->totalSubscribers = 0;

        return Craft::$app->elements->saveElement($list);
    }

    /**
     * Gets lists.
     *
     * @param Subscribers $subscriber
     *
     * @return array
     */
    public function getLists(Subscribers $subscriber = null)
    {
        $lists = [];

        /**
         * @var SubscribersRecord $subscriberRecord
         */
        $subscriberRecord = null;

        /**
         * @var $subscriber Subscribers
         */
        if ($subscriber != null AND (!empty($subscriber->email) OR !empty($subscriber->userId))) {
            $subscriberAttributes = array_filter([
                'email' => $subscriber->email,
                'userId' => $subscriber->userId
            ]);

            $subscriberRecord = SubscribersRecord::find()->where($subscriberAttributes)->one();
        }

        $listRecords = [];

        if ($subscriberRecord == null) {
            // Only findAll if we are not looking for a specific Subscriber, otherwise we want to return null
            if (empty($subscriber->email)) {
                $listRecords = ListsRecord::find()->all();
            }
        } else {
            $listRecords = $subscriberRecord->getLists()->all();
        }

        if (!empty($listRecords)) {

            foreach ($listRecords as $listRecord) {
                $list = new Lists();
                $list->setAttributes($listRecord->getAttributes(), false);
                $lists[] = $list;
            }
        }

        return $lists;
    }

    /**
     * Get the total number of lists for a given subscriber
     *
     * @param Subscribers $subscriber
     *
     * @return int
     */
    public function getListCount(Subscribers $subscriber = null)
    {
        $lists = $this->getLists($subscriber);

        return count($lists);
    }

    /**
     * Gets list with a given id.
     *
     * @param int $listId
     *
     * @return \craft\base\ElementInterface|mixed|null
     */
    public function getListById(int $listId)
    {
        return Craft::$app->getElements()->getElementById($listId);
    }

    /**
     * Returns an array of all lists that have subscribers.
     *
     * @return array
     */
    public function getListsWithSubscribers()
    {
        $lists = [];
        $records = ListsRecord::find()->all();
        if ($records) {
            foreach ($records as $record) {
                /**
                 * @var $record ListsRecord
                 */
                $subscribers = $record->getSubscribers()->all();

                if (empty($subscribers)) {
                    continue;
                }

                $lists[] = $record;
            }
        }

        return $lists;
    }

    /**
     * Gets or creates list
     *
     * @param Subscription $subscription
     *
     * @return Lists|\craft\base\ElementInterface|null
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \yii\base\Exception
     */
    public function getOrCreateList(Subscription $subscription)
    {
        $listRecord = ListsRecord::find()->where(['handle' => $subscription->listHandle])->one();

        $list = new Lists();
        /**
         * @var $settings Settings
         */
        $settings = Craft::$app->plugins->getPlugin('sprout-lists')->getSettings();

        // If no List exists, dynamically create one
        if ($listRecord) {
            /**
             * @var Lists $list
             */
            $list = Craft::$app->getElements()->getElementById($listRecord->id);

            if ($subscription->elementId) {
                $list->elementId = $subscription->elementId;
                $this->saveList($list);
            }
        } elseif ($settings->enableAutoList) {
            $list->type = SubscriberListType::class;
            $list->elementId = $subscription->elementId;
            $list->name = $subscription->listHandle;
            $list->handle = $subscription->listHandle;

            $this->saveList($list);
        } else {
            /**
             * @var $subscription Subscription
             */
            $subscription->addError('missing-list', 'List cannot be found.');
        }

        return $list;
    }

    // Subscriptions
    // =========================================================================

    /**
     * @param $subscription
     *
     * @return bool|mixed
     * @throws \Throwable
     */
    public function subscribe(Subscription $subscription)
    {
        /**
         * @var Settings $settings
         */
        $settings = Craft::$app->plugins->getPlugin('sprout-lists')->getSettings();

        $subscriber = new Subscribers();

        if (!empty($subscription->email)) {
            $subscriber->email = $subscription->email;
        }

        if ($subscription->userId !== null && $settings->enableUserSync) {
            $subscriber->userId = $subscription->userId;
        }

        try {
            // If our List doesn't exist, create a List Element on the fly
            $list = $this->getOrCreateList($subscription);

            if ($subscription->getErrors()) {
                return false;
            }

            // If our Subscriber doesn't exist, create a Subscriber Element on the fly
            $subscriber = $this->getSubscriber($subscriber);

            $subscriptionRecord = new SubscriptionRecord();

            if ($list) {
                $subscriptionRecord->listId = $list->id;
                $subscriptionRecord->subscriberId = $subscriber->id;

                // Create a criteria between our List Element and Subscriber Element
                if ($subscriptionRecord->save(false)) {
                    $this->updateTotalSubscribersCount($subscriptionRecord->listId);
                }
            }

            return true;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * @inheritDoc ListType::unsubscribe()
     *
     * @param Subscription $subscription
     *
     * @return bool
     */
    public function unsubscribe(Subscription $subscription)
    {
        /**
         * @var Settings $settings
         */
        $settings = Craft::$app->plugins->getPlugin('sprout-lists')->getSettings();

        $listAttributes = [
            'type' => $subscription->listType,
            'handle' => $subscription->listHandle
        ];

        if ($subscription->elementId) {
            $listAttributes['elementId'] = $subscription->elementId;
        }

        if ($subscription->id) {
            $list = ListsRecord::findOne($subscription->id);
        } else {
            $list = ListsRecord::find()
                ->where($listAttributes)
                ->one();
        }

        if (!$list) {
            return false;
        }

        if ($subscription->elementId) {
            $list->elementId = $list->id;
            $list->save();
        }

        // Determine the subscriber that we will un-subscribe
        $subscriberRecord = new SubscribersRecord();

        if (!empty($subscription->userId) && ($settings AND $settings->enableUserSync)) {
            $subscriberRecord = SubscribersRecord::find()->where([
                'userId' => $subscription->userId
            ])->one();
        } elseif (!empty($subscription->email)) {
            $subscriberRecord = SubscribersRecord::find()->where([
                'email' => $subscription->email
            ])->one();
        }

        if (!isset($subscriberRecord->id)) {
            return false;
        }

        // Delete the subscription that matches the List and Subscriber IDs
        $subscriptions = SubscriptionRecord::deleteAll([
            'listId' => $list->id,
            'subscriberId' => $subscriberRecord->id
        ]);

        if ($subscriptions != null) {
            $this->updateTotalSubscribersCount();

            return true;
        }

        return false;
    }

    /**
     * @inheritDoc ListType::isSubscribed()
     *
     * @param $criteria
     *
     * @return bool
     */
    public function isSubscribed(Subscription $subscription)
    {
        if (empty($subscription->listHandle)) {
            throw new \InvalidArgumentException(Craft::t('sprout-lists', 'Missing argument: `listHandle` is required by the isSubscribed variable'));
        }

        // We need a user ID or an email
        if ($subscription->userId === null && $subscription->email === null) {
            throw new \InvalidArgumentException(Craft::t('sprout-lists', 'Missing argument: `userId` or `email` are required by the isSubscribed variable'));
        }

        /**
         * @var $settings Settings
         */
        $settings = Craft::$app->plugins->getPlugin('sprout-lists')->getSettings();

        // however, if User Sync is not enabled, we need an email
        if ($settings->enableUserSync === true && $subscription->email === null) {
            throw new \InvalidArgumentException(Craft::t('sprout-lists', 'Missing argument: `email` is required by the isSubscribed variable with User Sync is enabled.'));
        }

        $listId = null;
        $subscriberId = null;

        $listAttributes = [
            'handle' => $subscription->listHandle
        ];

        if ($subscription->elementId) {
            $listAttributes['elementId'] = $subscription->elementId;
        }

        /**
         * @var ListsRecord $listRecord
         */
        $listRecord = ListsRecord::find()->where($listAttributes)->one();

        if ($listRecord) {
            $listId = $listRecord->id;
        } elseif (!$settings->enableAutoList) {
            /**
             * @var $subscription Subscription
             */
            $subscription->addError('missing-list', 'List cannot be found.');
        }

        $attributes = array_filter([
            'email' => $subscription->email,
            'userId' => $subscription->userId
        ]);

        $subscriberRecord = SubscribersRecord::find()->where($attributes)->one();

        if ($subscriberRecord) {
            $subscriberId = $subscriberRecord->id;
        }

        if ($listId != null && $subscriberId != null) {
            $subscriptionRecord = SubscriptionRecord::find()->where([
                'subscriberId' => $subscriberId,
                'listId' => $listId
            ])->one();

            if ($subscriptionRecord) {
                return true;
            }
        }

        return false;
    }

    /**
     * Saves a subscription
     *
     * @param Subscribers $subscriber
     *
     * @return bool
     * @throws \Exception
     */
    public function saveSubscriptions(Subscribers $subscriber)
    {
        try {
            SubscriptionRecord::deleteAll('subscriberId = :subscriberId', [
                ':subscriberId' => $subscriber->id
            ]);

            if (!empty($subscriber->subscriberLists)) {
                foreach ($subscriber->subscriberLists as $listId) {
                    $list = $this->getListById($listId);

                    if ($list) {
                        $subscriptionRecord = new SubscriptionRecord();
                        $subscriptionRecord->subscriberId = $subscriber->id;
                        $subscriptionRecord->listId = $list->id;

                        if (!$subscriptionRecord->save(false)) {

                            SproutLists::error($subscriptionRecord->getErrors());

                            throw new Exception(Craft::t('sprout-lists', 'Unable to save subscription.'));
                        }
                    } else {
                        throw new Exception(Craft::t('sprout-lists', 'The Subscriber List with id {listId} does not exists.', [
                            'listId' => $listId
                        ]));
                    }
                }
            }

            $this->updateTotalSubscribersCount();

            return true;
        } catch (\Exception $e) {
            Craft::error($e->getMessage());
            throw $e;
        }
    }

    // Subscribers
    // =========================================================================

    /**
     * Saves a subscriber
     *
     * @param Subscribers $subscriber
     *
     * @return bool
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \yii\base\Exception
     */
    public function saveSubscriber(Subscribers $subscriber)
    {
        if (!$subscriber->validate(null, false)) {
            return false;
        }

        if (Craft::$app->getElements()->saveElement($subscriber)) {
            $this->saveSubscriptions($subscriber);

            return true;
        }

        return false;
    }

    /**
     * Gets a subscriber
     *
     * @param Subscribers $subscriber
     *
     * @return Subscribers|\craft\services\Elements
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \yii\base\Exception
     */
    public function getSubscriber(Subscribers $subscriber)
    {
        $attributes = array_filter([
            'email' => $subscriber->email,
            'userId' => $subscriber->userId
        ]);

        $subscriberRecord = SubscribersRecord::find()->where($attributes)->one();

        if (!empty($subscriberRecord)) {
            $subscriber = Craft::$app->getElements()->getElementById($subscriberRecord->id);
        }

        // If no Subscriber was found, create one
        if (!$subscriber->id && $subscriber->userId !== null) {
            $user = Craft::$app->users->getUserById($subscriber->userId);

            if ($user) {
                $subscriber->email = $user->email;
            }
        }

        $this->saveSubscriber($subscriber);

        return $subscriber;
    }

    /**
     * Gets a subscriber with a given id.
     *
     * @param $id
     *
     * @return \craft\base\ElementInterface|null
     */
    public function getSubscriberById($id)
    {
        return Craft::$app->getElements()->getElementById($id);
    }

    /**
     * Deletes a subscriber.
     *
     * @param $id
     *
     * @return SubscriptionRecord
     * @throws \Throwable
     */
    public function deleteSubscriberById($id)
    {
        /**
         * @var $subscriber SubscriptionRecord
         */
        $subscriber = $this->getSubscriberById($id);

        if ($subscriber AND ($subscriber AND $subscriber != null)) {
            SproutLists::$app->subscribers->deleteSubscriberById($id);
        }

        $this->updateTotalSubscribersCount();

        return $subscriber;
    }

    /**
     * Gets the HTML output for the lists sidebar on the Subscriber edit page.
     *
     * @param $subscriberId
     *
     * @return string|\Twig_Markup
     * @throws Exception
     * @throws \Twig_Error_Loader
     */
    public function getSubscriberListsHtml($subscriberId)
    {
        $listIds = [];

        if ($subscriberId != null) {

            $subscriber = $this->getSubscriberById($subscriberId);

            if ($subscriber) {
                /**
                 * @var $subscriber Subscribers
                 */
                $listIds = $subscriber->getListIds();
            }
        }

        $lists = $this->getLists();

        $options = [];

        if (count($lists)) {
            foreach ($lists as $list) {
                $options[] = [
                    'label' => sprintf('%s', $list->name),
                    'value' => $list->id
                ];
            }
        }

        // Return a blank template if we have no lists
        if (empty($options)) {
            return '';
        }

        $html = Craft::$app->getView()->renderTemplate('sprout-base-lists/subscribers/_subscriberlists', [
            'options' => $options,
            'values' => $listIds
        ]);

        return Template::raw($html);
    }

    /**
     * Updates the totalSubscribers column in the db
     *
     * @param null $listId
     *
     * @return bool
     */
    public function updateTotalSubscribersCount($listId = null)
    {
        if ($listId == null) {
            $lists = ListsRecord::find()->all();
        } else {
            $list = ListsRecord::findOne($listId);

            $lists = [$list];
        }

        if (count($lists)) {
            foreach ($lists as $list) {

                if (!$list) {
                    continue;
                }

                $count = count($list->getSubscribers()->all());

                $list->totalSubscribers = $count;

                $list->save();
            }

            return true;
        }

        return false;
    }

    /**
     * @param $list
     *
     * @return int|mixed
     * @throws \Exception
     */
    public function getSubscriberCount(Lists $list)
    {
        $subscribers = $this->getSubscribers($list);

        return count($subscribers);
    }

    /**
     * @param $list
     *
     * @return array|mixed
     * @throws \Exception
     */
    public function getSubscribers(Lists $list)
    {
        if (empty($list->type)) {
            throw new \InvalidArgumentException(Craft::t('sprout-lists', 'Missing argument: "type" is required by the getSubscribers variable.'));
        }

        if (empty($list->handle)) {
            throw new \InvalidArgumentException(Craft::t('sprout-lists', 'Missing argument: "listHandle" is required by the getSubscribers variable.'));
        }

        $subscribers = [];

        if (empty($list)) {
            return $subscribers;
        }

        $listRecord = ListsRecord::find()->where([
            'type' => $list->type,
            'handle' => $list->handle
        ])->one();

        /**
         * @var $listRecord ListsRecord
         */
        if ($listRecord != null) {
            $subscribers = $listRecord->getSubscribers()->all();

            return $subscribers;
        }

        return $subscribers;
    }
}