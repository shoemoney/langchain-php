# Review 30 - qwen/qwen3-vl-235b-a22b-thinking
_asked 2026-09-30T13:23:11 - served by qwen/qwen3-vl-235b-a22b-thinking - 164s_

## 1. Metadata-only stream not treated as empty
**Severity:** MAJOR
**Evidence:** BaseChatModel.php stream() method
**Why it matters:** Stream yields nothing but doesn't throw when only metadata chunks are produced
**Suggested fix:** Track if any non-metadata chunks were yielded; throw if none

## 2. 200 status with invalid content not handled
**Severity:** MAJOR
**Evidence:** GuzzleHttpClient.php postStream() method
**Why it matters:** Downstream code expects valid content but may receive empty/invalid data
**Suggested fix:** Add content validation after 200 status check

## 3. Null content not handled in isMetadataOnly
**Severity:** MINOR
**Evidence:** BaseChatModel.php isMetadataOnly() method
**Why it matters:** Could cause incorrect chunk filtering in edge cases
**Suggested fix:** Add null check for content

## 4. Large error bodies consume excessive memory
**Severity:** MINOR
**Evidence:** GuzzleHttpClient.php postStream() method
**Why it matters:** Could cause memory exhaustion for large error responses
**Suggested fix:** Limit error body size or stream it

## 5. Exception in error handler loses original exception
**Severity:** MINOR
**Evidence:** BaseChatModel.php generateMessages() method
**Why it matters:** Makes debugging harder when error handling fails
**Suggested fix:** Preserve original exception with try/finally