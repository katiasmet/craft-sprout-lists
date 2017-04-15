<?php
namespace Craft;

class SproutListsPlugin extends BasePlugin
{
	/**
	 * @return string
	 */
	public function getName()
	{
		return 'Sprout Lists';
	}

	/**
	 * @return string
	 */
	public function getVersion()
	{
		return '0.6.1';
	}

	/**
	 * @return string
	 */
	public function getSchemaVersion()
	{
		return '0.6.1';
	}

	/**
	 * @return string
	 */
	public function getDeveloper()
	{
		return 'Barrel Strength Design';
	}

	/**
	 * @return string
	 */
	public function getDeveloperUrl()
	{
		return 'http://barrelstrengthdesign.com';
	}

	/**
	 * @return bool
	 */
	public function hasCpSection()
	{
		return true;
	}

	/**
	 * @return array
	 */
	public function registerCpRoutes()
	{
		return array(
			'sproutlists/lists/new' => array(
				'action' => 'sproutLists/lists/editListTemplate'
			),
			'sproutlists/lists/edit/(?P<listId>[\d]+)' => array(
				'action' => 'sproutLists/lists/editListTemplate'
			),
			'sproutlists/subscribers/new' => array(
				'action' => 'sproutLists/subscribers/editSubscriberTemplate'
			),
			'sproutlists/subscribers/(?P<listHandle>{handle})' =>
				'sproutlists/subscribers',

			'sproutlists/subscribers/edit/(?P<id>[\d]+)' => array(
				'action' => 'sproutLists/subscribers/editSubscriberTemplate'
			),
		);
	}

	/**
	 * Register our default Sprout Lists List Types
	 *
	 * @return array
	 */
	public function registerSproutListsListTypes()
	{
		Craft::import('plugins.sproutlists.contracts.SproutListsBaseListType');
		Craft::import('plugins.sproutlists.integrations.sproutlists.SproutLists_UserListType');
		Craft::import('plugins.sproutlists.integrations.sproutlists.SproutLists_SubscriberListType');

		return array(
			new SproutLists_SubscriberListType()
		);
	}

	/**
	 * Register Twig Extensions
	 *
	 * @return SproutListsTwigExtension
	 */
	public function addTwigExtension()
	{
		Craft::import('plugins.sproutlists.twigextensions.SproutListsTwigExtension');

		return new SproutListsTwigExtension();
	}
}

/**
 * @return SproutListsService
 */
function sproutLists()
{
	return Craft::app()->getComponent('sproutLists');
}
