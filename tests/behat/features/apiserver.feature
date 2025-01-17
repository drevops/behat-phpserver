Feature: API server.

  Ensure that Behat is capable of starting API PHP server and asserting that
  it can return the expected responses.

  @apiserver
  Scenario: Check the API server is running
    When I send a GET request to "/admin/status" in the API server
    Then the response status code should be 200
    And the response header should contain "X-Received-Requests" with value "0"
    And the response header should contain "X-Queued-Responses" with value "0"

  @apiserver
  Scenario: Assert that a single API response is returned correctly
    Given API will respond with:
    """
    {
      "code": 200,
      "reason": "OK",
      "headers": {
        "Content-Type": "application/json"
      },
      "body": {
        "Id": "test-id-1",
        "Slug": "test-slug-1"
      }
    }
    """
    # Assert that the expected response was stored.
    And I send a GET request to "/admin/status" in the API server
    And the response status code should be 200
    And the response header should contain "X-Received-Requests" with value "0"
    And the response header should contain "X-Queued-Responses" with value "1"

    When I send a GET request to "/someurl" in the API server
    Then the response status code should be 200
    And the response header should contain "X-Received-Requests" with value "1"
    And the response header should contain "X-Queued-Responses" with value "0"
    And the response header should contain "Content-Type" with value "application/json"

    And the response should contain "Id"
    And the response should contain "test-id-1"
    And the response should contain "Slug"
    And the response should contain "test-slug-1"

  @apiserver
  Scenario: Assert that multiple API responses are returned correctly
    Given the API will respond with:
    """
    {
      "code": 200,
      "headers": {
        "Content-Type": "application/json"
      },
      "body": {
        "Id": "test-id-1",
        "Slug": "test-slug-1"
      }
    }
    """
    And the API will respond with:
    """
    {
      "code": 200,
      "headers": {
        "Content-Type": "application/json"
      },
      "body": {
        "Id": "test-id-2",
        "Slug": "test-slug-2"
      }
    }
    """
    And the API will respond with:
    """
    {
      "code": 201
    }
    """

    # Assert that the expected response was stored.
    And I send a GET request to "/admin/status" in the API server
    And the response status code should be 200
    And the response header should contain "X-Received-Requests" with value "0"
    And the response header should contain "X-Queued-Responses" with value "3"

    When I send a GET request to "/someurl1" in the API server
    Then the response status code should be 200
    And the response header should contain "X-Received-Requests" with value "1"
    And the response header should contain "X-Queued-Responses" with value "2"
    And the response header should contain "Content-Type" with value "application/json"
    And the response header should contain "Content-Length" with value "39"
    And the response should contain "Id"
    And the response should contain "test-id-1"
    And the response should contain "Slug"
    And the response should contain "test-slug-1"

    When I send a GET request to "/someurl2" in the API server
    Then the response status code should be 200
    And the response header should contain "X-Received-Requests" with value "2"
    And the response header should contain "X-Queued-Responses" with value "1"
    And the response header should contain "Content-Type" with value "application/json"
    And the response header should contain "Content-Length" with value "39"
    And the response should contain "Id"
    And the response should contain "test-id-2"
    And the response should contain "Slug"
    And the response should contain "test-slug-2"

    When I send a GET request to "/someurl3" in the API server
    Then the response status code should be 201
    And the response header should contain "X-Received-Requests" with value "3"
    And the response header should contain "X-Queued-Responses" with value "0"
    And the response should not contain header "Content-Length"
    And the response should not contain "Id"
    And the response should not contain "test-id"
    And the response should not contain "Slug"
    And the response should not contain "test-slug"

  @apiserver
  Scenario: Assert that incorrectly formatted API responses trigger an error
    Given I send a GET request to "/admin/status" in the API server
    And the response status code should be 200
    And the response header should contain "X-Received-Requests" with value "0"
    And the response header should contain "X-Queued-Responses" with value "0"

    When I send a GET request to "/someurl1" in the API server
    Then the response status code should be 500
    And the response should not contain header "X-Received-Requests"
    And the response should not contain header "X-Queued-Responses"
    And the response header should contain "Content-Length" with value "33"
    And the response should contain "No responses in queue"
