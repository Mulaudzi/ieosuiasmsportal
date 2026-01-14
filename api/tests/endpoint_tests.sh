#!/bin/bash
# IEOSUIA SMS Portal - API Endpoint Test Script
# Tests all endpoints using curl

BASE_URL="${BASE_URL:-http://localhost/api}"
TEST_EMAIL="test_$(date +%s)@example.com"
TEST_PASSWORD="Test123456!"
AUTH_TOKEN=""
USER_ID=""
CONTACT_ID=""
TEMPLATE_ID=""
CAMPAIGN_ID=""

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Test counter
PASSED=0
FAILED=0

# Helper function to make API calls
api_call() {
    local method=$1
    local endpoint=$2
    local data=$3
    local token=$4
    
    local headers=()
    if [ -n "$token" ]; then
        headers+=("-H" "Authorization: Bearer $token")
    fi
    
    if [ -n "$data" ]; then
        headers+=("-H" "Content-Type: application/json")
        headers+=("-d" "$data")
    fi
    
    curl -s -X "$method" \
        "${BASE_URL}${endpoint}" \
        "${headers[@]}" \
        -w "\n%{http_code}"
}

# Test function
test_endpoint() {
    local name=$1
    local method=$2
    local endpoint=$3
    local data=$4
    local token=$5
    local expected_status=${6:-200}
    
    echo -n "Testing: $name... "
    
    local response=$(api_call "$method" "$endpoint" "$data" "$token")
    local http_code=$(echo "$response" | tail -n1)
    local body=$(echo "$response" | sed '$d')
    
    if [ "$http_code" -eq "$expected_status" ]; then
        echo -e "${GREEN}✓ PASSED${NC} (HTTP $http_code)"
        PASSED=$((PASSED + 1))
        echo "$body" | jq '.' 2>/dev/null || echo "$body"
        return 0
    else
        echo -e "${RED}✗ FAILED${NC} (Expected $expected_status, got $http_code)"
        FAILED=$((FAILED + 1))
        echo "Response: $body"
        return 1
    fi
}

echo "=========================================="
echo "IEOSUIA SMS Portal - API Test Suite"
echo "=========================================="
echo "Base URL: $BASE_URL"
echo ""

# Test 1: Health Check
test_endpoint "Health Check" "GET" "/up" "" "" 200

# Test 2: User Registration
REGISTER_DATA=$(cat <<EOF
{
  "name": "Test User",
  "email": "$TEST_EMAIL",
  "password": "$TEST_PASSWORD",
  "password_confirmation": "$TEST_PASSWORD"
}
EOF
)

echo "Registering user: $TEST_EMAIL"
RESPONSE=$(api_call "POST" "/auth/register" "$REGISTER_DATA" "")
HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | sed '$d')

if [ "$HTTP_CODE" -eq 201 ]; then
    echo -e "${GREEN}✓ Registration PASSED${NC}"
    AUTH_TOKEN=$(echo "$BODY" | jq -r '.token // empty')
    USER_ID=$(echo "$BODY" | jq -r '.user.id // empty')
    PASSED=$((PASSED + 1))
else
    echo -e "${RED}✗ Registration FAILED${NC} (HTTP $HTTP_CODE)"
    echo "$BODY"
    FAILED=$((FAILED + 1))
    exit 1
fi

# Test 3: User Login
LOGIN_DATA=$(cat <<EOF
{
  "email": "$TEST_EMAIL",
  "password": "$TEST_PASSWORD"
}
EOF
)

test_endpoint "User Login" "POST" "/auth/login" "$LOGIN_DATA" "" 200
AUTH_TOKEN=$(api_call "POST" "/auth/login" "$LOGIN_DATA" "" | sed '$d' | jq -r '.token // empty')

if [ -z "$AUTH_TOKEN" ]; then
    echo -e "${RED}Failed to get auth token${NC}"
    exit 1
fi

# Test 4: Get Current User
test_endpoint "Get Current User" "GET" "/auth/user" "" "$AUTH_TOKEN" 200

# Test 5: Create Contact
CONTACT_DATA=$(cat <<EOF
{
  "name": "John Doe",
  "phone": "+27123456789",
  "email": "john@example.com"
}
EOF
)

RESPONSE=$(api_call "POST" "/contacts" "$CONTACT_DATA" "$AUTH_TOKEN")
HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | sed '$d')

if [ "$HTTP_CODE" -eq 201 ]; then
    echo -e "${GREEN}✓ Create Contact PASSED${NC}"
    CONTACT_ID=$(echo "$BODY" | jq -r '.contact.id // empty')
    PASSED=$((PASSED + 1))
else
    echo -e "${RED}✗ Create Contact FAILED${NC} (HTTP $HTTP_CODE)"
    FAILED=$((FAILED + 1))
fi

# Test 6: Get Contacts
test_endpoint "Get Contacts" "GET" "/contacts" "" "$AUTH_TOKEN" 200

# Test 7: Update Contact
if [ -n "$CONTACT_ID" ]; then
    UPDATE_DATA='{"name": "John Updated"}'
    test_endpoint "Update Contact" "PUT" "/contacts/$CONTACT_ID" "$UPDATE_DATA" "$AUTH_TOKEN" 200
fi

# Test 8: Create Template
TEMPLATE_DATA=$(cat <<EOF
{
  "name": "Test SMS Template",
  "type": "sms",
  "content": "Hello {name}, this is a test message."
}
EOF
)

RESPONSE=$(api_call "POST" "/templates" "$TEMPLATE_DATA" "$AUTH_TOKEN")
HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | sed '$d')

if [ "$HTTP_CODE" -eq 201 ]; then
    echo -e "${GREEN}✓ Create Template PASSED${NC}"
    TEMPLATE_ID=$(echo "$BODY" | jq -r '.template.id // empty')
    PASSED=$((PASSED + 1))
else
    echo -e "${RED}✗ Create Template FAILED${NC} (HTTP $HTTP_CODE)"
    FAILED=$((FAILED + 1))
fi

# Test 9: Get Templates
test_endpoint "Get Templates" "GET" "/templates" "" "$AUTH_TOKEN" 200

# Test 10: Create SMS Campaign
CAMPAIGN_DATA=$(cat <<EOF
{
  "name": "Test SMS Campaign",
  "message": "Hello, this is a test SMS campaign.",
  "recipients": ["+27123456789"],
  "sender_id": "TEST"
}
EOF
)

RESPONSE=$(api_call "POST" "/sms/campaigns" "$CAMPAIGN_DATA" "$AUTH_TOKEN")
HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | sed '$d')

if [ "$HTTP_CODE" -eq 201 ]; then
    echo -e "${GREEN}✓ Create SMS Campaign PASSED${NC}"
    CAMPAIGN_ID=$(echo "$BODY" | jq -r '.campaign.id // empty')
    PASSED=$((PASSED + 1))
else
    echo -e "${RED}✗ Create SMS Campaign FAILED${NC} (HTTP $HTTP_CODE)"
    echo "$BODY"
    FAILED=$((FAILED + 1))
fi

# Test 11: Get SMS Campaigns
test_endpoint "Get SMS Campaigns" "GET" "/sms/campaigns" "" "$AUTH_TOKEN" 200

# Test 12: Get Wallet Stats
test_endpoint "Get Wallet Stats" "GET" "/wallet/stats" "" "$AUTH_TOKEN" 200

# Test 13: Get Dashboard Stats
test_endpoint "Get Dashboard Stats" "GET" "/dashboard/stats" "" "$AUTH_TOKEN" 200

# Test 14: Delete Template
if [ -n "$TEMPLATE_ID" ]; then
    test_endpoint "Delete Template" "DELETE" "/templates/$TEMPLATE_ID" "" "$AUTH_TOKEN" 204
fi

# Test 15: Delete Contact
if [ -n "$CONTACT_ID" ]; then
    test_endpoint "Delete Contact" "DELETE" "/contacts/$CONTACT_ID" "" "$AUTH_TOKEN" 204
fi

# Summary
echo ""
echo "=========================================="
echo "Test Summary"
echo "=========================================="
echo -e "${GREEN}Passed: $PASSED${NC}"
echo -e "${RED}Failed: $FAILED${NC}"
echo ""

if [ $FAILED -eq 0 ]; then
    echo -e "${GREEN}All tests passed!${NC}"
    exit 0
else
    echo -e "${RED}Some tests failed!${NC}"
    exit 1
fi
