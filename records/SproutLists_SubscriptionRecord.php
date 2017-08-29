<?php

namespace Craft;

/**
 * Class SproutLists_SubscriptionRecord
 *
 * @package Craft
 * --
 * @property int    $id
 * @property int    $listId
 * @property int    $subscriberId
 */
class SproutLists_SubscriptionRecord extends BaseRecord
{
	/**
	 * @return string
	 */
	public function getTableName()
	{
		return 'sproutlists_subscriptions';
	}

	/**
	 * @return array
	 */
	public function defineAttributes()
	{
		return array(
			'subscriberId' => array(AttributeType::Number)
		);
	}

	/**
	 * @return array
	 */
	public function defineRelations()
	{
		return array(
			'list'       => array(
				static::BELONGS_TO,
				'SproutLists_ListRecord',
				'listId',
				'required' => true,
				'onDelete' => static::CASCADE
			)
		);
	}

	/**
	 * @return array
	 */
	public function defineIndexes()
	{
		return array(
			array('columns' => array('listId'))
		);
	}
}