@block @block_online_users
<<<<<<< HEAD
Feature: The online users block allow you to see who is currently online
=======
Feature: The online users block allow you to see who is currently online on dashboard
>>>>>>> 9e7c3978895c7cab585c2f5234ca536151d3bef6
  In order to use the online users block on the dashboard
  As a user
  I can view the online users block on my dashboard

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
      | student1 | Student   | 1        | student1@example.com |
      | student2 | Student   | 2        | student2@example.com |

  Scenario: View the online users block on the dashboard and see myself
    Given I log in as "teacher1"
    Then I should see "Teacher 1" in the "Online users" "block"
<<<<<<< HEAD
=======
    And I should see "1 online user" in the "Online users" "block"
>>>>>>> 9e7c3978895c7cab585c2f5234ca536151d3bef6

  Scenario: View the online users block on the dashboard and see other logged in users
    Given I log in as "student2"
    And I log out
    And I log in as "student1"
    And I log out
    When  I log in as "teacher1"
    Then I should see "Teacher 1" in the "Online users" "block"
    And I should see "Student 1" in the "Online users" "block"
    And I should see "Student 2" in the "Online users" "block"
<<<<<<< HEAD
=======
    And I should see "3 online users" in the "Online users" "block"

  @javascript
  Scenario: Hide/show user's online status from/to other users in the online users block on dashboard
    Given I log in as "student1"
    And I should see "1 online user" in the "Online users" "block"
    And I should see "Student 1" in the "Online users" "block"
    And "Hide" "icon" should exist in the "#change-user-visibility" "css_element"
    When I click on "#change-user-visibility" "css_element"
    And I wait "1" seconds
    Then "Show" "icon" should exist in the "#change-user-visibility" "css_element"
    And I log out
    When I log in as "student2"
    Then I should see "1 online user" in the "Online users" "block"
    And I should see "Student 2" in the "Online users" "block"
    And I should not see "Student 1" in the "Online users" "block"
    And I log out
    When I log in as "student1"
    Then "Show" "icon" should exist in the "#change-user-visibility" "css_element"
    When I click on "#change-user-visibility" "css_element"
    And I wait "1" seconds
    Then "Hide" "icon" should exist in the "#change-user-visibility" "css_element"
    And I log out
    When I log in as "student2"
    Then I should see "2 online users" in the "Online users" "block"
    And I should see "Student 2" in the "Online users" "block"
    And I should see "Student 1" in the "Online users" "block"
>>>>>>> 9e7c3978895c7cab585c2f5234ca536151d3bef6
