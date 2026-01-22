@apiserver
Feature: API Server
  In order to test API responses
  As a developer
  I want to be able to configure the API server in various ways

  # No common background because we need to restart the server for each scenario

  #
  # Basic functionality
  #
  Scenario: Check the API server is running
    Given API server is running
    And API server is reset
    When I send a GET request to "/admin/status" in the API server
    Then the response status code should be 200
    And the response header should contain "X-Received-Requests" with value "0"
    And the response header should contain "X-Queued-Responses" with value "0"

  Scenario: API server reset functionality clears responses and requests
    Given API server is running
    And the API will respond with:
      """
      {
        "code": 200,
        "reason": "OK",
        "headers": {
          "Content-Type": "application/json"
        },
        "body": {
          "test": "data"
        }
      }
      """
    When I send a GET request to "/admin/status" in the API server
    Then the response header should contain "X-Queued-Responses" with value "1"
    When API server is reset
    And I send a GET request to "/admin/status" in the API server
    Then the response header should contain "X-Received-Requests" with value "0"
    And the response header should contain "X-Queued-Responses" with value "0"

  Scenario: Assert that incorrectly formatted API responses trigger an error
    Given API server is running
    And API server is reset
    When I send a GET request to "/someurl1" in the API server
    Then the response status code should be 500
    And the response should not contain header "X-Received-Requests"
    And the response should not contain header "X-Queued-Responses"
    And the response header should contain "Content-Length" with value "33"
    And the response should contain "No responses in queue"

  #
  # JSON responses
  #
  Scenario: Assert that a single API response is returned correctly
    Given API server is running
    And API server is reset
    Given the API will respond with:
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
    When I send a GET request to "/someurl" in the API server
    Then the response status code should be 200
    And the response header should contain "X-Received-Requests" with value "1"
    And the response header should contain "X-Queued-Responses" with value "0"
    And the response header should contain "Content-Type" with value "application/json"
    And the response should contain "Id"
    And the response should contain "test-id-1"
    And the response should contain "Slug"
    And the response should contain "test-slug-1"

  Scenario: Assert that multiple API responses are returned correctly
    Given API server is running
    And API server is reset
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
    And the API server should have 3 received requests
    And the API server should have 0 queued responses

  Scenario: Assert that "API will respond with JSON:" works correctly
    Given API server is running
    And API server is reset
    Given API will respond with JSON:
      """
      {
        "Id": "test-id-1",
        "Slug": "test-slug-1"
      }
      """
    And API will respond with JSON and 201 code:
      """
      {
        "Id": "test-id-2",
        "Slug": "test-slug-2"
      }
      """
    When I send a GET request to "/someurl1" in the API server
    Then the response status code should be 200
    And the response header should contain "X-Received-Requests" with value "1"
    And the response header should contain "X-Queued-Responses" with value "1"
    And the response header should contain "Content-Type" with value "application/json"
    And the response header should contain "Content-Length" with value "39"
    And the response should contain "Id"
    And the response should contain "test-id-1"
    And the response should contain "Slug"
    And the response should contain "test-slug-1"

    When I send a GET request to "/someurl2" in the API server
    Then the response status code should be 201
    And the response header should contain "X-Received-Requests" with value "2"
    And the response header should contain "X-Queued-Responses" with value "0"
    And the response header should contain "Content-Type" with value "application/json"
    And the response header should contain "Content-Length" with value "39"
    And the response should contain "Id"
    And the response should contain "test-id-2"
    And the response should contain "Slug"
    And the response should contain "test-slug-2"
    And the API server should have 2 received requests
    And the API server should have 0 responses queued

  Scenario: API server responds with JSON file content
    Given API server is running
    And API server is reset
    Given API will respond with file "test_data.json"
    When I send a GET request to "/"
    Then the response status code should be 200
    And the response should contain "Product A"
    And the response should contain "Product B"
    And the response should contain "Product C"
    And the response header "Content-Type" should be "application/json"

  Scenario: API server responds with XML file content
    Given API server is running
    And API server is reset
    Given API will respond with file "test_content.xml" and 201 code
    When I send a GET request to "/"
    Then the response status code should be 201
    And the response should contain "John Smith"
    And the response should contain "Jane Doe"
    And the response should contain "Robert Johnson"
    And the response header "Content-Type" should be "application/xml"

  Scenario: API server responds with HTML file content
    Given API server is running
    And API server is reset
    Given API will respond with file "test_page.html" and 202 code
    When I send a GET request to "/"
    Then the response status code should be 202
    And the response should be HTML
    And the response header "Content-Type" should contain "text/html"

  Scenario: API server responds with file from primary fixtures path
    Given API server is running
    And API server is reset
    And API will respond with file "test_data.json"
    When I send a GET request to "/"
    Then the response status code should be 200
    And the response should contain "Product A"
    And the response header "Content-Type" should be "application/json"

  Scenario: API server responds with file from secondary fixtures path
    Given API server is running
    And API server is reset
    And API will respond with file "secondary_data.txt" and 200 code
    When I send a GET request to "/"
    Then the response status code should be 200
    And the response should contain "sample text file in the secondary fixtures directory"
    And the response header "Content-Type" should contain "text/plain"

  #
  # Admin operations
  #
  Scenario: Clear only responses queue without affecting received requests count
    Given API server is running
    And API server is reset
    And the API will respond with JSON:
      """
      {"test": "response1"}
      """
    And the API will respond with JSON:
      """
      {"test": "response2"}
      """
    # Make a request to increment received requests count
    When I send a GET request to "/someurl" in the API server
    Then the API server should have 1 received request
    And the API server should have 1 queued response
    # Clear only responses - requests count should remain unchanged
    Given the API has no responses
    Then the API server should have 1 received request
    And the API server should have 0 queued responses

  Scenario: Debug API requests shows request information
    Given API server is running
    And API server is reset
    And the API will respond with JSON:
      """
      {"status": "ok"}
      """
    When I send a GET request to "/test/endpoint" in the API server
    And I debug API requests
    Then the API server should have 1 received request
