<?php
/**
 * ---------------------------------------------------------------------
 * Formcreator is a plugin which allows creation of custom forms of
 * easy access.
 * ---------------------------------------------------------------------
 * LICENSE
 *
 * This file is part of Formcreator.
 *
 * Formcreator is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Formcreator is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Formcreator. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 * @copyright Copyright © 2011 - 2021 Teclib'
 * @license   http://www.gnu.org/licenses/gpl.txt GPLv3+
 * @link      https://github.com/pluginsGLPI/formcreator/
 * @link      https://pluginsglpi.github.io/formcreator/
 * @link      http://plugins.glpi-project.org/#/plugin/formcreator
 * ---------------------------------------------------------------------
 */

namespace tests\units;
use GlpiPlugin\Formcreator\Tests\CommonTestCase;
use PluginFormcreatorFormAnswer;
use PluginFormcreatorTargetTicket;
use PluginFormcreatorTarget_Actor;
use CommonITILActor;
use RuleAction;
use User;
use Rule;
use RuleCriteria;
use CommonITILObject;
use Ticket;
use TicketValidation;
use PluginFormcreatorForm;

class PluginFormcreatorIssue extends CommonTestCase {
   public function beforeTestMethod($method) {
      global $CFG_GLPI;
      switch ($method) {
         case 'testGetSyncIssuesRequest':
         case 'testUpdateDateModOnNewFollowup':
            $this->login('glpi', 'glpi');
            $CFG_GLPI['use_notifications'] = 0;
            break;
      }
   }

   public function providerGetsyncIssuesRequest_simpleTicket() {
      $ticket = $this->getGlpiCoreItem(Ticket::class, [
         'name'    => 'simple ticket',
         'content' => 'foo',
         'status'  =>  Ticket::INCOMING,
         '_actors' => [
            'requester' => [
               0 => ['itemtype' => User::class,
                  'items_id' => User::getIdByName('glpi'), // glpi
                  'use_notification' => 1,
                  'alternative_email' => '',
               ]
            ]
         ]
      ]);
      $this->boolean($ticket->isNewItem())->isFalse();
      $ticket->getFromDB($ticket->getID());

      $ticket2 = $this->getGlpiCoreItem(Ticket::class, [
         'name' => '',
         'content' => 'foo',
         'status'  =>  Ticket::INCOMING,
         '_actors' => [
            'requester' => [
               0 => ['itemtype' => User::class,
                  'items_id' => User::getIdByName('glpi'), // glpi
                  'use_notification' => 1,
                  'alternative_email' => '',
               ]
            ]
         ]
      ]);
      $this->boolean($ticket2->isNewItem())->isFalse();
      $ticket2->getFromDB($ticket2->getID());
      $ticket2->update([
         'id' => $ticket2->getID(),
         'name' => '',
      ]);

      return [
         'simpleTicket' => [
            'item' => $ticket,
            'expected' => [
               'itemtype'      => Ticket::getType(),
               'items_id'      => $ticket->getID(),
               'display_id'    => 't_' . $ticket->getID(),
               'name'          => $ticket->fields['name'],
               'status'        => $ticket->fields['status'],
               'requester_id'  => $ticket->fields['users_id_recipient'],
               'date_creation' => $ticket->fields['date'],
               'date_mod'      => $ticket->fields['date_mod'],
            ],
         ],
         'simpleTicket_without_name' => [
            'item' => $ticket2,
            'expected' => [
               'itemtype'  => Ticket::getType(),
               'items_id'   => $ticket2->getID(),
               'display_id'    => 't_' . $ticket2->getID(),
               'name'          => '(' . $ticket2->getID() . ')',
               'status'        => $ticket2->fields['status'],
               'requester_id'  => $ticket2->fields['users_id_recipient'],
               'date_creation' => $ticket2->fields['date'],
               'date_mod'      => $ticket2->fields['date_mod'],
            ]
         ]
      ];
   }

   public function providerGetsyncIssuesRequest_simpleFormanswers() {
      $form = $this->getForm([
         'formanswer_name' => $this->getUniqueString(),
      ]);
      $formAnswer = new PluginFormcreatorFormAnswer();
      $formAnswer->add([
         'plugin_formcreator_forms_id' => $form->getID(),
      ]);
      $this->boolean($formAnswer->isNewItem())->isFalse();
      $formAnswer->getFromDB($formAnswer->getID());

      return [
         'simpleFormanswers' => [
            'item' => $formAnswer,
            'expected' => [
               'itemtype'      => PluginFormcreatorFormAnswer::getType(),
               'items_id'      => $formAnswer->getID(),
               'display_id'    => 'f_' . $formAnswer->getID(),
               'name'          => $formAnswer->fields['name'],
               'status'        => $formAnswer->fields['status'],
               'requester_id'  => $formAnswer->fields['requester_id'],
               'date_creation' => $formAnswer->fields['request_date'],
               'date_mod'      => $formAnswer->fields['request_date'],
            ],
         ],
      ];
   }

   public function providerGetSyncIssuesRequest_formAnswerWithOneTicket() {
      // case 1
      $form = $this->getForm();
      $targetTicket1 = new PluginFormcreatorTargetTicket();
      $targetTicket1->add([
         'plugin_formcreator_forms_id' => $form->getID(),
         'name' => 'foo',
      ]);
      $this->boolean($targetTicket1->isNewItem())->isFalse();

      $formAnswer = new PluginFormcreatorFormAnswer();
      $formAnswer->add([
         'plugin_formcreator_forms_id' => $form->getID(),
      ]);
      $this->boolean($formAnswer->isNewItem())->isFalse();
      $formAnswer->getFromDB($formAnswer->getID());
      $ticket = array_shift($formAnswer->targetList);
      $this->object($ticket)->isInstanceOf(Ticket::getType());

      // case 2
      // Add business rules to add 2 validators
      $rule = $this->getGlpiCoreItem(Rule::class, [
         'sub_type'  => RuleTicket::class,
         'name'      => 'add validators',
         'match'     => 'AND',
         'is_active' => 1,
         'condition' => 1,
      ]);
      $ruleCriteria = $this->getGlpiCoreItem(RuleCriteria::class, [
         $rule::getForeignKeyField() => $rule->getID(),
         'criteria'                  => '_groups_id_of_requester',
         'condition'                 => 1,
         'pattern'                   => User::getIdByName('normal'),
      ]);
      $ruleAction = $this->getGlpiCoreItem(RuleAction::class, [
         $rule::getForeignKeyField() => $rule->getID(),
         'action_type'               => 'add_validation',
         'field'                     => 'users_id_validate',
         'value'                     => User::getIdByName('glpi'),
      ]);
      $ruleAction = $this->getGlpiCoreItem(RuleAction::class, [
         $rule::getForeignKeyField() => $rule->getID(),
         'action_type'               => 'add_validation',
         'field'                     => 'users_id_validate',
         'value'                     => User::getIdByName('normal'),
      ]);
      $formAnswer = new PluginFormcreatorFormAnswer();
      $formAnswer->add([
         'plugin_formcreator_forms_id' => $form->getID(),
      ]);
      $rule->update([
         'id' => $rule->getID(),
         'is_active' => 0,
      ]);
      $this->boolean($formAnswer->isNewItem())->isFalse();
      $formAnswer->getFromDB($formAnswer->getID());
      $ticket2 = array_shift($formAnswer->targetList);
      $this->object($ticket2)->isInstanceOf(Ticket::getType());

      return [
         'formAnswerWithOneTicket' => [
            'item' => $ticket,
            'expected' => [
               'itemtype'      => Ticket::getType(),
               'items_id'      => $ticket->getID(),
               'display_id'    => 't_' . $ticket->getID(),
               'name'          => $ticket->fields['name'],
               'status'        => $ticket->fields['status'],
               'requester_id'  => $ticket->fields['users_id_recipient'],
               'date_creation' => $ticket->fields['date'],
               'date_mod'      => $ticket->fields['date_mod'],
            ],
         ],
         'formAnswer With One Ticket Having several validators' => [
            'item' => $ticket2,
            'expected' => [
               'itemtype'      => Ticket::getType(),
               'items_id'      => $ticket2->getID(),
               'display_id'    => 't_' . $ticket2->getID(),
               'name'          => $ticket2->fields['name'],
               'status'        => CommonITILObject::ASSIGNED,
               'requester_id'  => $ticket2->fields['users_id_recipient'],
               'date_creation' => $ticket2->fields['date'],
               'date_mod'      => $ticket2->fields['date_mod'],
            ],
         ],
      ];
   }

   public function providerGetSyncIssuesRequest_formAnswerWithSeveralTickets() {
      $form = $this->getForm();
      $targetTicket1 = new PluginFormcreatorTargetTicket();
      $targetTicket1->add([
         'plugin_formcreator_forms_id' => $form->getID(),
         'name' => 'foo',
      ]);
      $this->boolean($targetTicket1->isNewItem())->isFalse();
      $targetTicket2 = new PluginFormcreatorTargetTicket();
      $targetTicket2->add([
         'plugin_formcreator_forms_id' => $form->getID(),
         'name' => 'bar',
      ]);
      $this->boolean($targetTicket2->isNewItem())->isFalse();

      $formAnswer = new PluginFormcreatorFormAnswer();
      $formAnswer->add([
         'plugin_formcreator_forms_id' => $form->getID(),
      ]);
      $this->boolean($formAnswer->isNewItem())->isFalse();
      $formAnswer->getFromDB($formAnswer->getID());
      return [
         'formAnswerWithSeveralTickets' => [
            'item' => $formAnswer,
            'expected' => [
               'itemtype'      => PluginFormcreatorFormAnswer::getType(),
               'items_id'      => $formAnswer->getID(),
               'display_id'    => 'f_' . $formAnswer->getID(),
               'name'          => $formAnswer->fields['name'],
               'status'        => $formAnswer->fields['status'],
               'requester_id'  => $formAnswer->fields['requester_id'],
               'date_creation' => $formAnswer->fields['request_date'],
               'date_mod'      => $formAnswer->fields['request_date'],
            ],
         ],
      ];
   }

   public function providerGetSyncIssuesRequest_formanswerUnderValidation() {
      $form = $this->getForm([
         'validation_required' => PluginFormcreatorForm::VALIDATION_USER,
         '_validator_users' => [User::getIdByName('tech')] // tech
      ]);

      $formAnswer = new PluginFormcreatorFormAnswer();
      $formAnswer->add([
         'plugin_formcreator_forms_id' => $form->getID(),
         'formcreator_validator'       => User::getIdByName('tech') // Tech
      ]);
      $this->boolean($formAnswer->isNewItem())->isFalse();
      $formAnswer->getFromDB($formAnswer->getID());

      return [
         'formanswerUnderValidation' => [
            'item' => $formAnswer,
            'expected' => [
               'itemtype'            => PluginFormcreatorFormAnswer::getType(),
               'items_id'            => $formAnswer->getID(),
               'display_id'          => 'f_' . $formAnswer->getID(),
               'name'                => $formAnswer->fields['name'],
               'status'              => $formAnswer->fields['status'],
               'requester_id'        => $formAnswer->fields['requester_id'],
               'date_creation'       => $formAnswer->fields['request_date'],
               'date_mod'            => $formAnswer->fields['request_date'],
            ],
         ],
      ];
   }

   public function providerGetsyncIssuesRequest_ticketUnderValidation() {
      $ticket = $this->getGlpiCoreItem(Ticket::class, [
         'name'    => 'a ticket',
         'content' => 'foo',
         'status'  =>  Ticket::INCOMING,
         '_add_validation' => '0',
         'validatortype' => User::class,
         'users_id_validate' => [User::getIdByName('tech')], // Tech
         '_actors' => [
            'requester' => [
               0 => ['itemtype' => User::class,
                  'items_id' => User::getIdByName('glpi'), // glpi
                  'use_notification' => 1,
                  'alternative_email' => '',
               ]
            ]
         ]
      ]);
      $this->boolean($ticket->isNewItem())->isFalse();
      $ticket->getFromDB($ticket->getID());

      return [
         'ticketUnderValidation' => [
            'item' => $ticket,
            'expected' => [
               'itemtype'  => Ticket::getType(),
               'items_id'      => $ticket->getID(),
               'display_id'    => 't_' . $ticket->getID(),
               'name'          => $ticket->fields['name'],
               'status'        => PluginFormcreatorFormAnswer::STATUS_WAITING,
               'requester_id'  => $ticket->fields['users_id_recipient'],
               'date_creation' => $ticket->fields['date'],
               'date_mod'      => $ticket->fields['date_mod'],
            ],
         ],
      ];
   }

   public function providerGetsyncIssuesRequest_validatedTicket() {
      $ticket = $this->getGlpiCoreItem(Ticket::class, [
         'name'    => 'a ticket',
         'content' => 'foo',
         'status'  =>  Ticket::INCOMING,
         '_add_validation' => '0',
         'validatortype' => User::class,
         'users_id_validate' => [4], // Tech
         '_actors' => [
            'requester' => [
               0 => ['itemtype' => User::class,
                  'items_id' => User::getIdByName('glpi'), // glpi
                  'use_notification' => 1,
                  'alternative_email' => '',
               ]
            ]
         ]
      ]);
      $this->boolean($ticket->isNewItem())->isFalse();
      $ticket->getFromDB($ticket->getID());

      // Validate the ticket
      $ticketValidation = new TicketValidation();
      $ticketValidation->getFromDBByCrit([
         'tickets_id' => $ticket->getID(),
      ]);
      $this->boolean($ticketValidation->isNewItem())->isFalse();
      $ticketValidation->update([
         'id' => $ticketValidation->getID(),
         'status' => TicketValidation::ACCEPTED
      ]);

      $ticket2 = $this->getGlpiCoreItem(Ticket::class, [
         'name'    => 'a ticket',
         'content' => 'foo',
         'status'  =>  Ticket::INCOMING,
         '_add_validation' => '0',
         'validatortype' => User::class,
         'users_id_validate' => [User::getIdByName('tech'), User::getIdByName('normal')], // Tech
         '_actors' => [
            'requester' => [
               0 => ['itemtype' => User::class,
                  'items_id' => User::getIdByName('glpi'), // glpi
                  'use_notification' => 1,
                  'alternative_email' => '',
               ]
            ]
         ]
      ]);
      $this->boolean($ticket2->isNewItem())->isFalse();
      $ticket2->getFromDB($ticket2->getID());

      return [
         'validatedTicket' => [
            'item' => $ticket,
            'expected' => [
               'itemtype'      => Ticket::getType(),
               'items_id'      => $ticket->getID(),
               'display_id'    => 't_' . $ticket->getID(),
               'name'          => $ticket->fields['name'],
               'status'        => Ticket::INCOMING,
               'requester_id'  => $ticket->fields['users_id_recipient'],
               'date_creation' => $ticket->fields['date'],
               'date_mod'      => $ticket->fields['date_mod'],
            ],
         ],
         'ticket with multiple validators' => [
            'item' => $ticket2,
            'expected' => [
               'itemtype'      => Ticket::getType(),
               'items_id'      => $ticket2->getID(),
               'display_id'    => 't_' . $ticket2->getID(),
               'name'          => $ticket2->fields['name'],
               'status'        => PluginFormcreatorFormAnswer::STATUS_WAITING,
               'requester_id'  => $ticket2->fields['users_id_recipient'],
               'date_creation' => $ticket2->fields['date'],
               'date_mod'      => $ticket2->fields['date_mod'],
            ],
         ]
      ];
   }

   public function providerGetSyncIssuesRequest_FormAnswerWithSeveralRequesters() {
      $form = $this->getForm();
      $targetTicket1 = new PluginFormcreatorTargetTicket();
      $targetTicket1->add([
         'plugin_formcreator_forms_id' => $form->getID(),
         'name' => 'foo',
      ]);
      $this->boolean($targetTicket1->isNewItem())->isFalse();

      $actor1 = new PluginFormcreatorTarget_Actor();
      $actor1->add([
         'itemtype'         => $targetTicket1->getType(),
         'items_id'         => $targetTicket1->getID(),
         'actor_role'       => PluginFormcreatorTarget_Actor::ACTOR_TYPE_PERSON,
         'actor_type'       => CommonITILActor::REQUESTER,
         'actor_value'      => 3,
         'use_notification' => '1',
      ]);
      $this->boolean($actor1->isNewItem())->isFalse();
      $actor2 = new PluginFormcreatorTarget_Actor();
      $actor2->add([
         'itemtype'         => $targetTicket1->getType(),
         'items_id'         => $targetTicket1->getID(),
         'actor_role'       => PluginFormcreatorTarget_Actor::ACTOR_TYPE_PERSON,
         'actor_type'       => CommonITILActor::REQUESTER,
         'actor_value'      => 5,
         'use_notification' => '1',
      ]);
      $this->boolean($actor2->isNewItem())->isFalse();

      $formAnswer = new PluginFormcreatorFormAnswer();
      $formAnswer->add([
         'plugin_formcreator_forms_id' => $form->getID(),
      ]);
      $this->boolean($formAnswer->isNewItem())->isFalse();
      $formAnswer->getFromDB($formAnswer->getID());

      $ticket = array_shift($formAnswer->targetList);
      $this->object($ticket)->isInstanceOf(Ticket::getType());
      return [
         'formAnswerWithSeveralRequesters' => [
            'item' => $ticket,
            'expected' => [
               'itemtype'      => Ticket::getType(),
               'items_id'      => $ticket->getID(),
               'display_id'    => 't_' . $ticket->getID(),
               'name'          => $ticket->fields['name'],
               'status'        => $ticket->fields['status'],
               'requester_id'  => $ticket->fields['users_id_recipient'],
               'date_creation' => $ticket->fields['date'],
               'date_mod'      => $ticket->fields['date_mod'],
            ],
         ],
      ];
   }

   public function providerGetSyncIssuesRequest() {
      return array_merge(
         $this->providerGetsyncIssuesRequest_simpleTicket(),
         $this->providerGetsyncIssuesRequest_simpleFormanswers(),
         $this->providerGetSyncIssuesRequest_formAnswerWithOneTicket(),
         $this->providerGetSyncIssuesRequest_formAnswerWithSeveralTickets(),
         $this->providerGetSyncIssuesRequest_formanswerUnderValidation(),
         $this->providerGetsyncIssuesRequest_ticketUnderValidation(),
         $this->providerGetsyncIssuesRequest_validatedTicket(),
         $this->providerGetSyncIssuesRequest_FormAnswerWithSeveralRequesters()
      );
   }

   /**
    * @dataProvider providerGetSyncIssuesRequest
    *
    * @return void
    */
   public function testGetSyncIssuesRequest($item, $expected) {
      global $DB;

      // Find the row matching the issue in re-populate query
      // Implicitly tests itemtype and items_id columns
      $request = $this->getTestedClassName()::getSyncIssuesRequest();
      $result = $DB->request([
         'FROM'  => $request,
         'WHERE' => [
            'itemtype' => $item->getType(),
            'items_id' => $item->getID(),
         ]
      ]);
      $this->object($result)->isInstanceOf(\DBmysqlIterator::class);
      $row = $result->current();
      $this->array($row);

      // Test all fields described in expectations
      foreach ($expected as $key => $field) {
         $this->variable($row[$key])->isEqualTo($field, "mismatch in field '$key'");
      }

      // Test there are no other rows matching the form answer or ticket
      if ($item->getType() == Ticket::class) {
         $unwantedItems = $DB->request([
            'SELECT' => ['items_id'],
            'FROM' => \Item_Ticket::getTable(),
            'WHERE' => [
               'itemtype'   => PluginFormcreatorFormAnswer::getType(),
               'tickets_id' => $item->getID(),
            ],
         ]);
         if (count($unwantedItems) > 0) {
            $unwantedWhere = [
               'itemtype' => PluginFormcreatorFormAnswer::getType(),
            ];
            foreach ($unwantedItems as $row) {
               $unwantedWhere['items_id'][] = $row['items_id'];
            }
            // WHERE itemtype = 'PluginFormcreatorFormAnswer' AND items_id IN ( <list of numbers> )
            $result = $DB->request([
               'FROM'  => $request,
               'WHERE' => $unwantedWhere,
            ]);
            $this->integer(count($result))->isEqualTo(0);
         }
      }
      if ($item->getType() == PluginFormcreatorFormAnswer::class) {
         $unwantedItems = $DB->request([
            'SELECT' => ['tickets_id'],
            'FROM' => \Item_Ticket::getTable(),
            'WHERE' => [
               'itemtype'   => PluginFormcreatorFormAnswer::getType(),
               'items_id' => $item->getID(),
            ],
         ]);
         if (count($unwantedItems) > 0) {
            $unwantedWhere = [
               'itemtype' => Ticket::getType(),
            ];
            foreach ($unwantedItems as $row) {
               $unwantedWhere['items_id'][] = $row['tickets_id'];
            }
            // WHERE  itemtype = 'Ticket' AND items_id IN ( <list of numbers> )
            $result = $DB->request([
               'FROM'  => $request,
               'WHERE' => $unwantedWhere,
            ]);
            $this->integer(count($result))->isEqualTo(0);
         }
      }
   }

   public function testUpdateDateModOnNewFollowup() {
      $ticket = $this->getGlpiCoreItem(Ticket::class, [
         'name' => 'ticket',
         'content' => 'foo',
      ]);
      $this->boolean($ticket->isNewItem())->isFalse();
      $creationDate = $ticket->fields['date_creation'];

      $issue = new \PluginFormcreatorISsue();
      $issue->getFromDBByCrit([
         'itemtype'  => Ticket::getType(),
         'items_id'  => $ticket->getID(),
      ]);
      $this->boolean($issue->isNewItem())->isFalse();
      $this->string($issue->fields['date_creation'])->isEqualTo($creationDate);
      $this->string($issue->fields['date_mod'])->isEqualTo($creationDate);

      sleep(2); // 2 seconds sleep to change the current datetime
      $this->login('glpi', 'glpi'); // Needed to update the current datetime in session
      $followup = new \ITILFollowup();
      $followup->add([
         'itemtype' => Ticket::getType(),
         'items_id' => $ticket->getID(),
         'content' => 'bar'
      ]);
      $this->boolean($followup->isNewItem())->isFalse();
      $ticket = new Ticket();
      $ticket->getFromDB($issue->fields['items_id']);
      $this->boolean($ticket->isNewItem())->isFalse();
      $this->string($ticket->fields['date_mod'])->isNotEqualTo($creationDate);
      $modDate = $ticket->fields['date_mod'];

      $issue = new \PluginFormcreatorISsue();
      $issue->getFromDBByCrit([
         'itemtype' => Ticket::getType(),
         'items_id' => $ticket->getID(),
      ]);
      $this->string($issue->fields['date_creation'])->isEqualTo($creationDate);
      $this->string($issue->fields['date_mod'])->isEqualTo($modDate);
   }

}