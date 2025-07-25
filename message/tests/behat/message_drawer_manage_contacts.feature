@core @core_message @javascript
Feature: Manage contacts
  In order to communicate with fellow users
  As a user
  I need to be able to add, decline and remove users

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | 1        | student1@example.com |
      | student2 | Student   | 2        | student2@example.com |
      | student3 | Student   | 3        | student3@example.com |
      | student4 | Student   | 4        | student4@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | course1 | C1 |
    And the following "course enrolments" exist:
      | user | course | role |
      | student1 | C1 | student |
      | student2 | C1 | student |
      | student3 | C1 | student |
      | student4 | C1 | student |
    And the following "message contacts" exist:
      | user     | contact |
      | student1 | student2 |
    And the following config values are set as admin:
      | messaging         | 1 |
      | messagingallusers | 1 |
      | messagingminpoll  | 1 |

  Scenario: Send a 'contact request' to someone to add a contact
    Given I log in as "student1"
    Then I open messaging
    And I select "Student 4" user in messaging
    And I open contact menu
    And I click on "Add to contacts" "link"
    And I click on "Add" "button"
    And I log out
    And I log in as "student3"
    And I select "Student 4" user in messaging
    And I open contact menu
    And I click on "Add to contacts" "link"
    And I click on "Add" "button"
    And I should see "Contact request sent"
    And I log out
    And I log in as "student4"
    Then I should see "2" in the "//div[@data-region='popover-region-messages']//*[@data-region='count-container']" "xpath_element"
    And I open messaging
    And I click on "Contacts" "link"
    Then I should see "2" in the "//div[@data-region='view-contacts']//*[@data-region='contact-request-count']" "xpath_element"
    And I click on "Requests" "link_or_button"
    And I click on "Student 1 Would like to contact you" "link"
    Then I should see "Accept and add to contacts"
    And I click on "Accept and add to contacts" "link_or_button"
    And I should not see "Accept and add to contacts"
    And I log out
    And I log in as "student1"
    And I open messaging
    And I click on "Contacts" "link"
    And I should see "Student 4" in the "//*[@data-section='contacts']" "xpath_element"

  Scenario: Send a 'contact request' to someone to add a contact in the profile page
    Given I am on the "student4" "user > profile" page logged in as student3
    And I should see "Add to contacts"
    When I click on "Add to contacts" "link"
    Then I should see "Contact request sent"
    And I log out
    And I am on the "student3" "user > profile" page logged in as student4
    And I should see "Waiting to be added as contact"
    And I open messaging
    And I click on "Contacts" "link"
    And I click on "Requests" "link_or_button"
    And I click on "Student 3 Would like to contact you" "link"
    And I should see "Accept and add to contacts"
    And I click on "Accept and add to contacts" "link_or_button"
    And I should not see "Accept and add to contacts"
    And I log out
    And I am on the "student4" "user > profile" page logged in as student3
    And I should see "Remove from contacts"

  Scenario: Decline a 'contact request' from someone
    Given I log in as "student1"
    Then I open messaging
    And I select "Student 3" user in messaging
    And I open contact menu
    And I click on "Add to contacts" "link"
    And I click on "Add" "button"
    And I should see "Contact request sent"
    And I log out
    And I log in as "student3"
    Then I should see "1" in the "//div[@data-region='popover-region-messages']//*[@data-region='count-container']" "xpath_element"
    And I open messaging
    And I click on "Contacts" "link"
    Then I should see "1" in the "//div[@data-region='view-contacts']//*[@data-region='contact-request-count']" "xpath_element"
    And I click on "Requests" "link_or_button"
    And I click on "Student 1 Would like to contact you" "link"
    Then I should see "Accept and add to contacts"
    And I click on "Decline" "link_or_button"
    And I should not see "Accept and add to contacts"
    And I open contact menu
    Then I should see "Add to contacts" in the "//div[@data-region='header-container']" "xpath_element"

  Scenario: Remove existing contact
    Given I log in as "student1"
    Then I open messaging
    And I click on "Contacts" "link"
    And I click on "Student 2" "link" in the "//*[@data-section='contacts']" "xpath_element"
    And I open contact menu
    And I click on "Remove from contacts" "link"
    And I click on "Remove" "button"
    And I go back in "view-conversation" message drawer
    And I should see "No contacts" in the "//*[@data-region='empty-message-container']" "xpath_element"

  Scenario: Prevent sending a 'contact request' if user access is not permitted
    Given the following config values are set as admin:
      | messagingallusers | 0 |
    When I am on the "student1" "user > profile" page logged in as admin
    Then I should not see "Add to contacts"
    And I click on "Message" "link"
    And I click on "Conversation actions menu" "button"
    And I should not see "Add to contacts"
    And I click on "User info" "link"
    And I should not see "Add to contacts"

  Scenario: Disabling messagingallusers preserves the contact request sent status
    Given I am on the "student1" "user > profile" page logged in as admin
    And I click on "Add to contacts" "link"
    And I should see "Contact request sent"
    And the following config values are set as admin:
      | messagingallusers | 0 |
    When I reload the page
    Then I should see "Contact request sent"
