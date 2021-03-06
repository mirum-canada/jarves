<?php
/**
 * This file is part of Jarves.
 *
 * (c) Marc J. Schmidt <marc@marcjschmidt.de>
 *
 *     J.A.R.V.E.S - Just A Rather Very Easy [content management] System.
 *
 *     http://jarves.io
 *
 * To get the full copyright and license information, please view the
 * LICENSE file, that was distributed with this source code.
 */

namespace Jarves\Tests\Service\ACL;

use Jarves\ACLRequest;
use Jarves\Configuration\Condition;
use Jarves\Model\Acl;
use Jarves\Model\Base\UserQuery;
use Jarves\Model\DomainQuery;
use Jarves\Model\Group;
use Jarves\Model\Node;
use Jarves\Model\NodeQuery;
use Jarves\Model\User;
use Jarves\Tests\AuthTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Test\Model\Item;
use Test\Model\ItemCategory;
use Test\Model\ItemCategoryQuery;
use Test\Model\ItemQuery;
use Test\Model\Test;
use Test\Model\TestQuery;

class ObjectTest extends AuthTestCase
{
    public function testConditionToSql()
    {
        $condition = new Condition();

        $condition2 = new Condition();
        $condition2->addAnd([
            'title', '=', 'TestNode tree'
        ]);

        $condition->addAnd($condition2);
        $condition->addOr([
            '1', '=', '1'
        ]);

        $params = [];
        $sql = $this->getConditionOperator()->standardConditionToSql($condition, $params, 'jarves/node');

        $expectedArray = [
            [
                ['title', '=', 'TestNode tree']
            ],
            'OR',
            [
                '1', '=', '1'
            ]
        ];
        $this->assertEquals($expectedArray, $condition->toArray());
        $this->assertEquals([':p1' => 'TestNode tree'], $params);
        $this->assertEquals(' system_node.title = :p1  OR 1= 1', $sql);
    }

    public function testNestedSubPermission()
    {
        $this->getACL()->setCaching(false);
        $this->getACL()->removeObjectRules('jarves/node');

        $tokenStorage = $this->getTokenStorage();

        $token = new UsernamePasswordToken(UserQuery::create()->findOneByUsername('test'), null, "main");
        $tokenStorage->setToken($token);

        $user = $this->getPageStack()->getUser();

        $this->assertEquals('test', $user->getUsername());

        $domain = DomainQuery::create()->findOne();
        $root = NodeQuery::create()->findRoot($domain->getId());

        $subNode = new Node();
        $subNode->setTitle('TestNode tree');
        $subNode->insertAsFirstChildOf($root);
        $subNode->save();

        $subNode2 = new Node();
        $subNode2->setTitle('TestNode sub');
        $subNode2->insertAsFirstChildOf($subNode);
        $subNode2->save();

        //make access for all
        $rule = new Acl();
        $rule->setAccess(true);
        $rule->setObject('jarves/node');
        $rule->setTargetType(\Jarves\ACL::TARGET_TYPE_USER);
        $rule->setTargetId($user->getId());
        $rule->setMode(\Jarves\ACL::MODE_ALL);
        $rule->setConstraintType(\Jarves\ACL::CONSTRAINT_ALL);
        $rule->setPrio(2);
        $rule->save();

        //revoke access for all children of `TestNode tree`
        $rule2 = new Acl();
        $rule2->setAccess(false);
        $rule2->setObject('jarves/node');
        $rule2->setTargetType(\Jarves\ACL::TARGET_TYPE_USER);
        $rule2->setTargetId($user->getId());
        $rule2->setMode(\Jarves\ACL::MODE_ALL);
        $rule2->setConstraintType(\Jarves\ACL::CONSTRAINT_CONDITION);
        $rule2->setConstraintCode(json_encode([
            'title', '=', 'TestNode tree'
        ]));
        $rule2->setPrio(3);
        $rule2->setSub(true);
        $rule2->save();

        $this->getCacher()->invalidateCache('core');

        $node1RequestListing = ACLRequest::create('jarves/node', $subNode->getId())->onlyListingMode();
        $node2RequestListing = ACLRequest::create('jarves/node', $subNode2->getId())->onlyListingMode();

        $this->assertFalse($this->getACL()->check($node1RequestListing));
        $this->assertFalse($this->getACL()->check($node2RequestListing));

        $items = $this->getObjects()->getBranch('jarves/node', $subNode->getId(), null, 1, null, [
            'permissionCheck' => true
        ]);

        $this->assertNull($items, 'rule2 revokes the access to all elements');
        $item = $this->getObjects()->get('jarves/node', $subNode2->getId(), [
            'permissionCheck' => true
        ]);
        $this->assertNull($item);



        // Deactivate sub
        $rule2->setSub(false);
        $rule2->save();

        $this->assertFalse($this->getACL()->check($node1RequestListing));
        $this->assertTrue($this->getACL()->check($node2RequestListing));
        $items = $this->getObjects()->getBranch('jarves/node', $subNode->getId(), null, 1, null, [
            'permissionCheck' => true
        ]);
        $this->assertEquals('TestNode sub', $items[0]['title'], 'We got TestNode sub');
        $item = $this->getObjects()->get('jarves/node', $subNode2->getId(), [
            'permissionCheck' => true
        ]);
        $this->assertEquals('TestNode sub', $item['title'], 'We got TestNode sub');



        // Activate access
        $rule2->setAccess(true);
        $rule2->save();
        $this->assertTrue($this->getACL()->check($node1RequestListing));
        $this->assertTrue($this->getACL()->check($node2RequestListing));

        $items = $this->getObjects()->getBranch('jarves/node', $subNode->getId(), null, 1, null, [
            'permissionCheck' => true
        ]);
        $this->assertEquals('TestNode sub', $items[0]['title'], 'We got TestNode sub');


        $subNode->delete();
        $subNode2->delete();
        $rule->delete();
        $rule2->delete();
        $this->getACL()->setCaching(true);
    }

    public function xtestSpeed()
    {
        $item = new Item();
        $item->setTitle('Item 1');
        $item->save();

        debugPrint('start');
        $objectItem = $this->getObjects()->get('test/item', ['id' => $item->getId()]);
        debugPrint('---');
        $objectItem = $this->getObjects()->get('test/item', ['id' => $item->getId()]);
        debugPrint('done');

        $item->delete();
    }

    public function testRuleCustom()
    {
        ItemCategoryQuery::create()->deleteAll();
        ItemQuery::create()->deleteAll();
        TestQuery::create()->deleteAll();
        $this->getACL()->setCaching(true);
        $this->getACL()->removeObjectRules('test/item');

        $user = new User();
        $user->setUsername('testuser');
        $user->save();

        $item1 = new Item();
        $item1->setTitle('Item 1');
        $item1->save();

        $item2 = new Item();
        $item2->setTitle('Item test');
        $item2->save();

        $rule = new Acl();
        $rule->setAccess(true);
        $rule->setObject('test/item');
        $rule->setTargetType(\Jarves\ACL::TARGET_TYPE_USER);
        $rule->setTargetId($user->getId());
        $rule->setMode(\Jarves\ACL::MODE_ALL);
        $rule->setConstraintType(\Jarves\ACL::CONSTRAINT_ALL);
        $rule->setPrio(2);
        $rule->save();

        $rule = new Acl();
        $rule->setAccess(false);
        $rule->setObject('test/item');
        $rule->setTargetType(\Jarves\ACL::TARGET_TYPE_USER);
        $rule->setTargetId($user->getId());
        $rule->setMode(\Jarves\ACL::MODE_ALL);
        $rule->setConstraintType(\Jarves\ACL::CONSTRAINT_CONDITION);
        $rule->setConstraintCode(json_encode([
            ['title', 'LIKE', '%test']
        ]));
        $rule->setPrio(3);
        $rule->save();

        $item1ListingRequest = ACLRequest::create('test/item', $item1->getId())
            ->onlyListingMode()
            ->targetUser($user->getId());

        $item2ListingRequest = ACLRequest::create('test/item', $item2->getId())
            ->onlyListingMode()
            ->targetUser($user->getId());

        $access1 = $this->getACL()->check($item1ListingRequest);
        $access2 = $this->getACL()->check($item2ListingRequest);

        $this->assertTrue($access1, 'item1 has access as the second rule doesnt grab and first rule says all access=true');
        $this->assertFalse($access2, 'no access to item2 as we have defined access=false in second rule.');

        $user->delete();

        $this->getACL()->setCaching(true);
        $this->getACL()->removeObjectRules('test/item');
    }

    public function testRulesWithFields()
    {
        ItemCategoryQuery::create()->deleteAll();
        ItemQuery::create()->deleteAll();
        TestQuery::create()->deleteAll();
        $this->getACL()->setCaching(false);
        $this->getACL()->removeObjectRules('test/item');

        $user = new User();
        $user->setUsername('TestUser');
        $user->save();

        $group = new Group();
        $group->setName('ACL Test group');
        $group->addUser($user);
        $group->save();

        $cat1 = new ItemCategory();
        $cat1->setName('Nein');

        $item1 = new Item();
        $item1->setTitle('Item 1');
        $item1->addItemCategory($cat1);
        $item1->save();

        $cat2 = new ItemCategory();
        $cat2->setName('Hiiiii');

        $item2 = new Item();
        $item2->setTitle('Item 2');
        $item2->addItemCategory($cat2);
        $item2->save();

        $this->getACL()->removeObjectRules('test/item');
        $fields = array(
            'oneCategory' => array(
                array(
                    'access' => false,
                    'condition' => array(array('id', '>', $cat1->getId()))
                )
            )
        );
        $this->getACL()->setObjectUpdate('test/item', \Jarves\ACL::TARGET_TYPE_USER, $user->getId(), true, $fields);


        $testItemRequest = ACLRequest::create('test/item')
            ->targetUser($user->getId())
            ->onlyUpdateMode();

        $this->assertFalse($this->getACL()->check($testItemRequest->setField(['oneCategory' => $cat2->getId()])));
        $this->assertTrue($this->getACL()->check($testItemRequest->setField(['oneCategory' => $cat1->getId()])));

        $this->getACL()->removeObjectRules('test/item');
        $fields = array(
            'oneCategory' => array(
                array(
                    'access' => false,
                    'condition' => array(array('name', '=', 'Nein'))
                )
            )
        );

        $this->getACL()->setObjectUpdate('test/item', \Jarves\ACL::TARGET_TYPE_USER, $user->getId(), true, $fields);


        $this->assertTrue($this->getACL()->check($testItemRequest->setField(['oneCategory' => $cat2->getId()])));
        $this->assertFalse($this->getACL()->check($testItemRequest->setField(['oneCategory' => $cat1->getId()])));

        $this->getACL()->removeObjectRules('test/item');

        $fields = array(
            'title' => array(
                array(
                    'access' => false,
                    'condition' => array(array('title', 'LIKE', 'peter %'))
                )
            )
        );
        $this->getACL()->setObjectUpdate('test/item', \Jarves\ACL::TARGET_TYPE_USER, $user->getId(), true, $fields);

        $this->assertTrue($this->getACL()->check($testItemRequest->setField(['title' => 'Heidenau'])));
        $this->assertTrue($this->getACL()->check($testItemRequest->setField(['title' => 'peter'])));

        $this->assertFalse($this->getACL()->check($testItemRequest->setField(['title' => 'peter 2'])));

        $this->assertFalse($this->getACL()->check($testItemRequest->setField(['title' => 'peter asdad'])));

        $this->getACL()->removeObjectRules('test/item');

        $fields = array('title' => array(array('access' => false, 'condition' => array(array('title', '=', 'peter')))));
        $this->getACL()->setObjectUpdate('test/item', \Jarves\ACL::TARGET_TYPE_USER, $user->getId(), true, $fields);

        $this->assertTrue($this->getACL()->check($testItemRequest->setField(['title' => 'Heidenau'])));
        $this->assertFalse($this->getACL()->check($testItemRequest->setField(['title' => 'peter'])));
        $this->assertTrue($this->getACL()->check($testItemRequest->setField(['title' => 'peter2'])));

        $this->getACL()->setCaching(true);
        $this->getACL()->removeObjectRules('test/item');
    }

    public function testObjectGeneral()
    {
        ItemQuery::create()->deleteAll();
        TestQuery::create()->deleteAll();
        $this->getACL()->removeObjectRules('test/item');
        $this->getACL()->setCaching(false);

        $user = new User();
        $user->setUsername('TestUser');
        $user->save();

        $group = new Group();
        $group->setName('ACL Test group');
        $group->addUser($user);
        $group->save();

        $item1 = new Item();
        $item1->setTitle('Item 1');
        $item1->save();

        $item2 = new Item();
        $item2->setTitle('Item 2');
        $item2->save();

        $test1 = new Test();
        $test1->setName('Test 1');
        $test1->save();

        $aclRequestItem1OnlyListing = ACLRequest::create('test/item', $item1->getId())
            ->onlyListingMode();

        $this->assertFalse(
            $this->getACL()->check($aclRequestItem1OnlyListing->targetGroup($group->getId())),
            'we have no rules, so everyone except admin user and admin group has no access.'
        );

        $this->assertTrue(
            $this->getACL()->check($aclRequestItem1OnlyListing->targetGroup(1)),
            'we have no rules, so only group admin has access.'
        );

        $this->assertTrue(
            $this->getACL()->check($aclRequestItem1OnlyListing->targetUser(1)),
            'we have no rules, so only user admin has access.'
        );


        $this->getACL()->removeObjectRules('test/item');
        $this->getACL()->setObjectList('test/item', \Jarves\ACL::TARGET_TYPE_GROUP, $group->getId(), true);

        $this->assertTrue(
            $this->getACL()->check($aclRequestItem1OnlyListing->targetGroup($group->getId())),
            'testGroup got list access to all test/item objects.'
        );

        $this->getACL()->setObjectListExact('test/item', $item1->getId(), \Jarves\ACL::TARGET_TYPE_GROUP, $group->getId(), false);
        $this->assertFalse(
            $this->getACL()->check($aclRequestItem1OnlyListing->targetGroup($group->getId())),
            'testGroup got list access-denied to item 1.'
        );

        $aclRequestItem2OnlyListing = ACLRequest::create('test/item', $item2->getId())
            ->onlyListingMode();

        $this->assertTrue(
            $this->getACL()->check($aclRequestItem2OnlyListing->targetGroup($group->getId())),
            'testGroup still have access to item2.'
        );

        $this->getACL()->setObjectListExact('test/item', $item2->getId(), \Jarves\ACL::TARGET_TYPE_GROUP, $group->getId(), false);

        $this->assertFalse(
            $this->getACL()->check($aclRequestItem2OnlyListing->targetGroup($group->getId())),
            'testGroup does not have access to item2 anymore.'
        );

        $acl = $this->getACL()->setObjectListExact('test/item', $item2->getId(), \Jarves\ACL::TARGET_TYPE_USER, $user->getId(), true);

        $this->assertTrue(
            $this->getACL()->check($aclRequestItem2OnlyListing->targetUser($user->getId())),
            'testUser got access through a rule for only him.'
        );

        $acl->setAccess(false);
        $acl->save();

        $this->assertFalse(
            $this->getACL()->check($aclRequestItem2OnlyListing->targetUser($user->getId())),
            'testUser got no-access through a rule for only him.'
        );

        //access to every item
        $acl = $this->getACL()->setObjectList('test/item', \Jarves\ACL::TARGET_TYPE_GROUP, $group->getId(), true);

        $this->assertTrue(
            $this->getACL()->check($aclRequestItem2OnlyListing->targetUser($user->getId())),
            'testUser has now access to all items through his group.'
        );
        $this->assertTrue(
            $this->getACL()->check($aclRequestItem1OnlyListing->targetGroup($group->getId())),
            'testGroup has now access to all items.'
        );
        $this->assertTrue(
            $this->getACL()->check($aclRequestItem2OnlyListing->targetGroup($group->getId())),
            'testGroup has now access to all items.'
        );

        //remove the acl item that gives access to anything.
        $acl->delete();

        $this->assertFalse(
            $this->getACL()->check($aclRequestItem2OnlyListing->targetUser($user->getId())),
            'testUser has no access anymore, since we deleted the access-for-all rule.'
        );
        $this->assertFalse(
            $this->getACL()->check($aclRequestItem1OnlyListing->targetGroup($group->getId())),
            'testGroup has no access anymore to all items (item1).'
        );
        $this->assertFalse(
            $this->getACL()->check($aclRequestItem2OnlyListing->targetGroup($group->getId())),
            'testGroup has no access anymore to all items (item2).'
        );

        //check checkListCondition
        $this->getACL()->setObjectListCondition(
            'test/item',
            array(array('id', '>', $item1->getId())),
            \Jarves\ACL::TARGET_TYPE_GROUP,
            $group->getId(),
            true
        );
        $this->assertTrue(
            $this->getACL()->check($aclRequestItem2OnlyListing->targetGroup($group->getId())),
            'testGroup has access to all items after item1'
        );

        $this->assertFalse(
            $this->getACL()->check($aclRequestItem1OnlyListing->targetGroup($group->getId())),
            'testGroup has access to all items after item1, but only > , so not item1 itself.'
        );

        //revoke anything to object 'test\item'
        $this->getACL()->setObjectList('test/item', \Jarves\ACL::TARGET_TYPE_GROUP, $group->getId(), false);

        $this->assertFalse(
            $this->getACL()->check($aclRequestItem2OnlyListing->targetGroup($group->getId())),
            'testGroup has no access to all items after item1'
        );

        //check against object test
        $aclRequestTest1OnlyListing = ACLRequest::create('test/test', $test1->getId())
            ->onlyListingMode();
        $this->getACL()->setObjectListExact('test/test', $test1->getId(), \Jarves\ACL::TARGET_TYPE_GROUP, $group->getId(), true);
        $this->assertTrue(
            $this->getACL()->check($aclRequestTest1OnlyListing->targetGroup($group->getId())),
            'testGroup has access test1.'
        );

        $this->getACL()->setObjectList('test/test', \Jarves\ACL::TARGET_TYPE_GROUP, $group->getId(), false);
        $this->assertFalse(
            $this->getACL()->check($aclRequestTest1OnlyListing->targetGroup($group->getId())),
            'testGroup has no access test1.'
        );

        $this->getACL()->setCaching(true);
        $this->getACL()->removeObjectRules('test/item');
    }

}
