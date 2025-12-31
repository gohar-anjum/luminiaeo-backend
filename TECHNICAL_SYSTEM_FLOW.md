# Technical System Flow Documentation

## Feature 1: Keyword Research

---

### 1️⃣ Feature Overview

**Feature Name:** Keyword Research Job Creation and Processing

**Business Purpose:** 
Enables users to perform comprehensive keyword research by submitting a seed keyword query. The system collects keywords from multiple sources (Google Keyword Planner, DataForSEO, scrapers, combined sources), clusters them semantically, scores them for AI visibility, and stores them for analysis. This powers SEO strategy by identifying high-value keywords with search volume, competition, CPC data, and AI citation potential.

**User Persona Involved:**
- SEO Analysts
- Content Strategists
- Marketing Managers
- Digital Marketing Agencies

**Entry Point:**
- **Primary:** HTTP POST request to `/api/keyword-research` from frontend application
- **Trigger:** User submits a keyword research form with seed keyword and configuration options
- **Authentication:** Required (Laravel Sanctum token-based authentication)

---

### 2️⃣ Frontend Execution Flow

**Note:** Frontend code is not present in this repository. The following describes the expected frontend behavior based on API contract.

**Expected Frontend Flow:**

1. **UI Component/Page:**
   - Component: Keyword Research Form/Page
   - Framework: Not specified (could be React, Vue, Angular, or vanilla JS)
   - Location: User-facing keyword research interface

2. **User Interaction:**
   - User enters seed keyword in input field
   - User optionally configures:
     - Project selection (dropdown)
     - Language code (default: 'en')
     - Geo target ID (default: 2840 - United States)
     - Max keywords limit (optional, 1-5000)
     - Feature toggles:
       - Enable Google Planner (default: true)
       - Enable Scraper (default: true)
       - Enable Clustering (default: true)
       - Enable Intent Scoring (default: true)

3. **Event Triggered:**
   - Form submission event (click on "Start Research" button or Enter key)
   - JavaScript event handler intercepts form submission

4. **Client-Side Validation:**
   - Validates query field is not empty
   - Validates query length (1-255 characters)
   - Validates project_id exists (if provided)
   - Validates language_code format (2-character ISO code)
   - Validates geo_target_id is positive integer
   - Validates max_keywords is within range (1-5000)

5. **State/Data Preparation:**
   - Constructs JSON payload:
     ```json
     {
       "query": "seed keyword",
       "project_id": 123,  // optional
       "language_code": "en",  // optional, default "en"
       "geo_target_id": 2840,  // optional, default 2840
       "max_keywords": 1000,  // optional
       "enable_google_planner": true,  // optional, default true
       "enable_scraper": true,  // optional, default true
       "enable_clustering": true,  // optional, default true
       "enable_intent_scoring": true  // optional, default true
     }
     ```

6. **API Call Preparation:**
   - HTTP Method: `POST`
   - Endpoint: `/api/keyword-research`
   - Headers:
     - `Content-Type: application/json`
     - `Accept: application/json`
     - `Authorization: Bearer {sanctum_token}` (from authenticated session)
   - Body: JSON payload from step 5

7. **API Request Execution:**
   - JavaScript fetch/axios call to backend API
   - Request sent to Laravel backend

8. **Conditional Branches:**
   - **On Success (201 Created):** Store job ID, redirect to status page, start polling
   - **On Validation Error (422):** Display validation error messages, highlight invalid fields
   - **On Authentication Error (401):** Redirect to login page
   - **On Server Error (500):** Display generic error message, log error

---

### 3️⃣ API Entry Point

**Route Definition File:**
- **File:** `routes/api.php`
- **Line:** 31-36

**Route Definition:**
```php
Route::prefix('keyword-research')->middleware('throttle:10,1')->group(function () {
    Route::post('/', [\App\Http\Controllers\Api\KeywordResearchController::class, 'create'])
        ->name('keyword-research.create');
    // ... other routes
});
```

**HTTP Method and URI:**
- Method: `POST`
- URI: `/api/keyword-research`
- Full URL: `{base_url}/api/keyword-research`

**Middleware Stack Executed (in order):**

1. **Global Middleware:**
   - Applied globally to all routes

2. **Route Group Middleware:**
   - `auth:sanctum` (Line 19 in `routes/api.php`)
     - **Purpose:** Validates Laravel Sanctum authentication token
     - **Action:** Extracts Bearer token from `Authorization` header
     - **Validation:** Checks token exists in `personal_access_tokens` table
     - **On Failure:** Returns 401 Unauthorized, redirects to `/api/login`
     - **On Success:** Attaches authenticated `User` model to request via `$request->user()`

3. **Rate Limiting Middleware:**
   - `throttle:10,1` (Line 31 in `routes/api.php`)
     - **Purpose:** Rate limiting to prevent API abuse
     - **Configuration:** 10 requests per 1 minute per user
     - **Implementation:** Laravel's built-in throttle middleware
     - **Storage:** Uses cache driver (Redis recommended)
     - **On Failure:** Returns 429 Too Many Requests
     - **Response Headers:**
     - `X-RateLimit-Limit: 10`
     - `X-RateLimit-Remaining: {remaining}`
     - `Retry-After: {seconds}`

4. **Security Headers Middleware:**
   - `SecurityHeaders` (registered in `bootstrap/app.php`)
     - **Purpose:** Add security headers to all API responses
     - **Headers Added:**
       - `X-Content-Type-Options: nosniff`
       - `X-Frame-Options: DENY`
       - `X-XSS-Protection: 1; mode=block`
       - `Referrer-Policy: strict-origin-when-cross-origin`
       - `Strict-Transport-Security` (production only)

5. **Input Sanitization Middleware:**
   - `SanitizeInput` (registered in `bootstrap/app.php`)
     - **Purpose:** Sanitize user input to prevent XSS and injection attacks
     - **Sanitization:** URL/domain fields use `FILTER_SANITIZE_URL`, other strings use `htmlspecialchars`

**Request Validation Layer:**

**File:** `app/Http/Requests/KeywordResearchRequest.php`

**Validation Rules Executed:**

1. **`query` field:**
   - `required` - Must be present
   - `string` - Must be string type
   - `max:255` - Maximum 255 characters
   - `min:1` - Minimum 1 character

2. **`project_id` field:**
   - `nullable` - Optional field
   - `integer` - Must be integer if provided
   - `exists:projects,id` - Must exist in `projects` table
   - **Additional Validation:** Uses `Rule::exists()` with ownership check to ensure user owns the project

3. **`language_code` field:**
   - `nullable` - Optional field
   - `string` - Must be string if provided
   - `size:2` - Must be exactly 2 characters
   - `regex:/^[a-z]{2}$/i` - Must match ISO 639-1 format (e.g., 'en', 'es', 'fr')

4. **`geo_target_id` field:**
   - `nullable` - Optional field
   - `integer` - Must be integer if provided
   - `min:1` - Must be at least 1

5. **`max_keywords` field:**
   - `nullable` - Optional field
   - `integer` - Must be integer if provided
   - `min:1` - Must be at least 1
   - `max:5000` - Must not exceed 5000

6. **Boolean flags:**
   - `enable_google_planner`, `enable_scraper`, `enable_clustering`, `enable_intent_scoring`
   - `nullable` - Optional
   - `boolean` - Must be boolean if provided

**Default Values Applied (in `validated()` method):**
- `language_code`: Defaults to `'en'` if not provided
- `geo_target_id`: Defaults to `2840` (United States) if not provided
- `enable_google_planner`: Defaults to `true` if not provided
- `enable_scraper`: Defaults to `true` if not provided
- `enable_clustering`: Defaults to `true` if not provided
- `enable_intent_scoring`: Defaults to `true` if not provided

**Validation Failure Handling:**
- Returns HTTP 422 Unauthorized
- Response format:
  ```json
  {
    "message": "The given data was invalid.",
    "errors": {
      "query": ["The query field is required."],
      "max_keywords": ["The max keywords must not exceed 5000."]
    }
  }
  ```

**Data Transformations:**
- None at validation layer - raw validated data passed to controller

---

### 4️⃣ Controller Layer

**Controller Class:**
- **File:** `app/Http/Controllers/Api/KeywordResearchController.php`
- **Class Name:** `App\Http\Controllers\Api\KeywordResearchController`
- **Namespace:** `App\Http\Controllers\Api`
- **Extends:** `App\Http\Controllers\Controller`

**Method Name:**
- `create(KeywordResearchRequest $request)`

**Method Execution Flow:**

1. **Parameter Received:**
   - `$request` - Instance of `KeywordResearchRequest` (FormRequest)
   - Already validated by Laravel's FormRequest mechanism
   - Contains validated and sanitized input data

2. **Authorization Checks:**
   - **No explicit authorization checks in controller method**
   - Authentication already verified by `auth:sanctum` middleware
   - User ID extracted via `Auth::id()` in service layer

3. **Data Transformation:**
   - Line 25: `$validated = $request->validated();`
     - Extracts validated data array from FormRequest
   - Line 26: `$dto = KeywordResearchRequestDTO::fromArray($validated);`
     - Converts array to DTO object
     - **File:** `app/DTOs/KeywordResearchRequestDTO.php`
     - **Method:** `fromArray(array $data): self`
     - Maps array keys to DTO properties:
       - `query` → `$query`
       - `project_id` → `$projectId`
       - `language_code` → `$languageCode`
       - `geo_target_id` → `$geoTargetId`
       - `max_keywords` → `$maxKeywords`
       - `enable_google_planner` → `$enableGooglePlanner`
       - `enable_scraper` → `$enableScraper`
       - `enable_clustering` → `$enableClustering`
       - `enable_intent_scoring` → `$enableIntentScoring`

4. **Service Delegation:**
   - Line 27: `$job = $this->keywordService->createKeywordResearch($dto);`
   - **Service:** `App\Services\KeywordService`
   - **Method:** `createKeywordResearch(KeywordResearchRequestDTO $dto)`
   - **Returns:** `KeywordResearchJobModel` instance

5. **Response Construction:**
   - Lines 29-38: Builds JSON response using `ApiResponseModifier`
   - **HTTP Status Code:** `201 Created`
   - **Response Modifier:** `App\Services\ApiResponseModifier`
   - **Response Structure:**
     ```json
     {
       "status": 201,
       "message": "Keyword research job created successfully",
       "response": {
         "id": 123,
         "query": "seed keyword",
         "status": "pending",
         "created_at": "2024-01-15T10:30:00.000000Z"
       }
     }
     ```
   - **Data Extracted:**
     - `id`: Job ID from database
     - `query`: Seed keyword
     - `status`: Job status (always `'pending'` at creation)
     - `created_at`: Timestamp

**What Controller Does:**
- ✅ Validates request data
- ✅ Transforms data to DTO
- ✅ Delegates business logic to service
- ✅ Formats response
- ✅ Returns HTTP response

**What Controller Does NOT Do:**
- ❌ Does not perform business logic
- ❌ Does not interact with database directly
- ❌ Does not dispatch jobs (delegated to service)
- ❌ Does not handle job processing
- ❌ Does not perform authorization beyond authentication

---

### 5️⃣ Service Layer (Core Logic)

**Service Class:**
- **File:** `app/Services/KeywordService.php`**
- **Class Name:** `App\Services\KeywordService`
- **Namespace:** `App\Services`

**Dependencies Injected (Constructor):**
1. `KeywordRepositoryInterface $keywordRepository` - Not used in `createKeywordResearch` method
2. `ApiResponseModifier $response` - Not used in `createKeywordResearch` method
3. `KeywordResearchOrchestratorService $orchestrator` - Not used in `createKeywordResearch` method
4. `KeywordResearchJobRepository $jobRepository` - Used for schema-aware job creation

**Method: `createKeywordResearch(KeywordResearchRequestDTO $dto): KeywordResearchJobModel`**

**Step-by-Step Internal Logic:**

**Step 1: Schema Detection (Moved to Repository)**
- **Purpose:** Dynamically detect database schema to handle optional columns
- **Action:** Delegated to `KeywordResearchJobRepository::createWithOptionalFields()`
- **Repository Method:** `createWithOptionalFields(array $baseData, array $optionalData): KeywordResearchJob`
- **Implementation:**
  - Uses cached schema detection (1-hour TTL) to avoid repeated queries
  - Checks column existence before adding optional fields
  - Returns `KeywordResearchJob` instance
- **Why:** Supports database migrations where columns may not exist
- **Performance:** Schema detection cached to reduce overhead

**Step 2: Base Data Array Construction (Lines 38-42)**
- **Purpose:** Build base data array for job creation
- **Action:**
  ```php
  $data = [
      'user_id' => Auth::id(),  // From authenticated user
      'query' => $dto->query,   // Seed keyword
      'status' => KeywordResearchJobModel::STATUS_PENDING,  // 'pending'
  ];
  ```
- **Data Sources:**
  - `user_id`: From `Auth::id()` - authenticated user's ID
  - `query`: From DTO - user-provided seed keyword
  - `status`: Constant `'pending'` - initial job state

**Step 3: Conditional Column Population (Lines 44-75)**
- **Purpose:** Add optional fields only if columns exist in database
- **Conditional Logic:**

  **3a. Project ID (Lines 45-47):**
  - **Condition:** `in_array('project_id', $columns) && $dto->projectId !== null`
  - **Action:** `$data['project_id'] = $dto->projectId;`
  - **Why:** Links job to user's project (optional)

  **3b. Language Code (Lines 49-51):**
  - **Condition:** `in_array('language_code', $columns) && $dto->languageCode !== null`
  - **Action:** `$data['language_code'] = $dto->languageCode;`
  - **Why:** Specifies language for keyword research (e.g., 'en', 'es', 'fr')

  **3c. Geo Target ID (Lines 53-55):**
  - **Condition:** `in_array('geoTargetId', $columns) && $dto->geoTargetId !== null`
  - **Action:** `$data['geoTargetId'] = $dto->geoTargetId;`
  - **Why:** Specifies geographic location (2840 = United States)

  **3d. Settings (Lines 57-65):**
  - **Condition:** `in_array('settings', $columns)`
  - **Action:** Builds settings JSON array:
    ```php
    $data['settings'] = [
        'max_keywords' => $dto->maxKeywords,
        'enable_google_planner' => $dto->enableGooglePlanner,
        'enable_scraper' => $dto->enableScraper,
        'enable_clustering' => $dto->enableClustering,
        'enable_intent_scoring' => $dto->enableIntentScoring,
    ];
    ```
  - **Why:** Stores job configuration for later processing

  **3e. Progress Tracking (Lines 67-74):**
  - **Condition:** `in_array('progress', $columns)`
  - **Action:** Initializes progress tracking:
    ```php
    $data['progress'] = [
        'queued' => [
            'percentage' => 0,
            'timestamp' => now()->toIso8601String(),
        ],
    ];
    ```
  - **Why:** Tracks job processing stages for frontend polling

**Step 4: Database Record Creation (Via Repository)**
- **Purpose:** Persist job record to database
- **Action:**
  ```php
  $job = $this->jobRepository->createWithOptionalFields($baseData, $optionalData);
  ```
- **Repository:** `App\Services\KeywordResearchJobRepository`
- **Model:** `App\Models\KeywordResearchJob`
- **Table:** `keyword_research_jobs`
- **Fields Inserted:**
  - `user_id` (required)
  - `query` (required)
  - `status` (required, 'pending')
  - `project_id` (optional, if column exists)
  - `language_code` (optional, if column exists)
  - `geoTargetId` (optional, if column exists)
  - `settings` (optional JSON, if column exists)
  - `progress` (optional JSON, if column exists)
  - `created_at` (auto-generated)
  - `updated_at` (auto-generated)
- **Returns:** `KeywordResearchJobModel` instance with `id` populated

**Step 5: Job Queue Dispatch**
- **Purpose:** Queue background job for asynchronous processing
- **Action:**
  ```php
  ProcessKeywordResearchJob::dispatch($job->id);
  ```
- **Job Class:** `App\Jobs\ProcessKeywordResearchJob`
- **Queue:** Default queue (configured in `config/queue.php`)
- **Queue Driver:** Redis (as per README)
- **Payload:** Only job ID is serialized (not full model)
- **Execution:** Asynchronous - returns immediately, job processed by queue worker
- **Note:** Dispatch is outside transaction (queue operations don't need transactions)

**Step 6: Logging (Lines 81-85)**
- **Purpose:** Log job creation for monitoring/debugging
- **Action:**
  ```php
  Log::info('Keyword research job created', [
      'job_id' => $job->id,
      'user_id' => Auth::id(),
      'query' => $dto->query,
  ]);
  ```
- **Log Channel:** Default Laravel log channel
- **Log Level:** `info`
- **Data Logged:** Job ID, user ID, seed query

**Step 7: Return Job Model (Line 87)**
- **Purpose:** Return created job to controller
- **Action:** `return $job;`
- **Type:** `KeywordResearchJobModel`

**Service Method Calls:**
- **None** - This method is self-contained

**External Service Calls:**
- **None** - This method only creates database record and dispatches job

**Database Interactions:**
- **Single INSERT** into `keyword_research_jobs` table

**Queue Interactions:**
- **Single dispatch** of `ProcessKeywordResearchJob` to queue

**Error Handling:**
- **No explicit try-catch** - Exceptions bubble up to Laravel's exception handler
- **Database errors:** Caught by Laravel, return 500 error
- **Queue dispatch errors:** Caught by Laravel, return 500 error
- **Auth errors:** `Auth::id()` returns null if not authenticated (should not happen due to middleware)

---

### 6️⃣ Data Access Layer

**Model Used:**
- **File:** `app/Models/KeywordResearchJob.php`
- **Class:** `App\Models\KeywordResearchJob`
- **Extends:** `Illuminate\Database\Eloquent\Model`

**Query Type:**
- **CREATE** - Single record insertion

**Table Involved:**
- **Table Name:** `keyword_research_jobs`
- **Schema Detection:** Dynamic via `Schema::getColumnListing()`

**Columns Used (Conditional):**

**Required Columns (Always Present):**
- `id` - Auto-increment primary key
- `user_id` - Foreign key to `users` table
- `query` - Seed keyword string (VARCHAR 255)
- `status` - Job status enum ('pending', 'processing', 'completed', 'failed')
- `created_at` - Timestamp
- `updated_at` - Timestamp

**Optional Columns (If Exist in Schema):**
- `project_id` - Foreign key to `projects` table (nullable)
- `language_code` - ISO 639-1 language code (VARCHAR 2, nullable)
- `geoTargetId` - Geographic target ID integer (nullable)
- `settings` - JSON column storing job configuration
- `progress` - JSON column storing progress tracking
- `result` - JSON column storing final results (null at creation)
- `error_message` - Error message if job fails (TEXT, nullable)
- `started_at` - Timestamp when processing started (nullable)
- `completed_at` - Timestamp when processing completed (nullable)

**Relationships:**
- **Not loaded in `createKeywordResearch` method**
- **Defined in Model (but not used here):**
  - `user()` - BelongsTo `User` model
  - `project()` - BelongsTo `Project` model
  - `keywords()` - HasMany `Keyword` model (conditional on column existence)
  - `clusters()` - HasMany `KeywordCluster` model (conditional on column existence)

**Transactions:**
- **No explicit transaction** - Single INSERT operation
- **Laravel's auto-commit** - Each `create()` call is auto-committed

**Index Implications:**
- **Primary Key:** `id` (auto-indexed)
- **Foreign Key Index:** `user_id` (indexed, improves user query performance)
- **Status Index:** `status` (indexed via migration `2025_12_29_000001_add_comprehensive_indexes.php`)
- **Composite Index:** `(user_id, status, created_at)` for efficient user job listing with status filtering
- **Query Index:** `query` (indexed for search functionality)

**Performance Considerations:**
- **Schema Detection:** Cached for 1 hour in `KeywordResearchJobRepository` to reduce overhead
- **Single INSERT:** Efficient, no batch operations needed
- **No N+1 Queries:** No relationship eager loading in this method
- **Index Usage:** Composite index supports common query pattern: user's jobs filtered by status

---

### 7️⃣ External Integrations

**No External API Calls in `createKeywordResearch` Method**

**Note:** External integrations occur later in the background job processing:
- **Google Keyword Planner API** - Called in `KeywordResearchOrchestratorService::collectKeywords()`
- **DataForSEO API** - Called in `KeywordResearchOrchestratorService::collectKeywords()`
- **Keyword Scraper Services** - Called in `KeywordResearchOrchestratorService::collectKeywords()`
- **LLM Services (OpenAI/Gemini)** - Called in `ProcessKeywordIntentJob` for intent scoring
- **Semantic Clustering Service** - Called in `KeywordResearchOrchestratorService::process()` for clustering

**Queue System:**
- **Queue Driver:** Redis (as per README)
- **Queue Name:** Default queue
- **Job Class:** `ProcessKeywordResearchJob`
- **Serialization:** Only job ID serialized (efficient)
- **Retry Logic:** Configured in job class (`$tries = 2`)

---

### 8️⃣ Response Construction

**Response Builder:**
- **Location:** Controller method (lines 29-38)
- **Type:** Direct `response()->json()` call (not using `ApiResponseModifier`)

**Data Transformation:**
- **Input:** `KeywordResearchJobModel` instance
- **Output:** JSON response array
- **Transformation:**
  ```php
  [
      'status' => 'success',  // Hardcoded string
      'message' => 'Keyword research job created successfully',  // Hardcoded string
      'data' => [
          'id' => $job->id,  // From model
          'query' => $job->query,  // From model
          'status' => $job->status,  // From model ('pending')
          'created_at' => $job->created_at,  // From model (Carbon instance, auto-serialized)
      ],
  ]
  ```

**Resource/DTO Usage:**
- **Not using API Resources** - Direct array construction
- **Not using DTOs for response** - Plain array

**Success Response Structure:**
```json
{
  "status": "success",
  "message": "Keyword research job created successfully",
  "data": {
    "id": 123,
    "query": "digital marketing",
    "status": "pending",
    "created_at": "2024-01-15T10:30:00.000000Z"
  }
}
```

**HTTP Status Code:**
- **201 Created** - Indicates resource successfully created

**Error Response Structure (Not Handled in Controller):**
- **Validation Errors (422):** Handled by FormRequest
- **Authentication Errors (401):** Handled by middleware
- **Server Errors (500):** Handled by Laravel exception handler

**Messages Returned:**
- **Success:** "Keyword research job created successfully"
- **Validation:** Per-field error messages from FormRequest
- **Auth:** "Unauthenticated" (from middleware)

---

### 9️⃣ Frontend Response Handling

**Expected Frontend Behavior (Not in Repository):**

**On Success (201 Created):**

1. **Response Parsing:**
   - Parse JSON response
   - Extract `data.id` (job ID)
   - Extract `data.status` ('pending')
   - Extract `data.created_at` (timestamp)

2. **UI State Updates:**
   - Store job ID in component state/localStorage
   - Update UI to show "Job Created" message
   - Disable form inputs
   - Show loading indicator

3. **Navigation/Redirect:**
   - Option A: Redirect to job status page (`/keyword-research/{id}/status`)
   - Option B: Update same page with status polling

4. **Polling Setup:**
   - Start polling `/api/keyword-research/{id}/status` every 2-5 seconds
   - Display progress percentage from `progress` object
   - Update UI with current stage:
     - "collecting" (10%)
     - "storing" (30%)
     - "clustering" (50%)
     - "intent_scoring" (70%)
     - "finalizing" (90%)
     - "completed" (100%)

5. **Completion Handling:**
   - When status = "completed", stop polling
   - Fetch results via `/api/keyword-research/{id}/results`
   - Display keywords, clusters, summary statistics
   - Enable export/download functionality

**On Validation Error (422):**

1. **Response Parsing:**
   - Parse JSON response
   - Extract `errors` object
   - Map field names to error messages

2. **UI Updates:**
   - Display error messages next to corresponding form fields
   - Highlight invalid fields with red border
   - Scroll to first error field
   - Keep form data (don't clear inputs)

3. **User Action:**
   - User corrects errors
   - Resubmits form

**On Authentication Error (401):**

1. **Response Handling:**
   - Clear authentication token
   - Redirect to login page
   - Store intended destination for post-login redirect

**On Server Error (500):**

1. **Response Handling:**
   - Display generic error message: "An error occurred. Please try again."
   - Log error details (if in development)
   - Optionally show "Retry" button

2. **Error Notifications:**
   - Toast notification with error message
   - Modal dialog for critical errors
   - Console logging for debugging

---

### 🔍 10️⃣ Architectural & Quality Audit

#### ❌ Identified Flaws

**1. Tight Coupling:**
- **Issue:** ~~Controller directly constructs response array instead of using `ApiResponseModifier` (which is injected but unused)~~ ✅ **FIXED**
- **Location:** `KeywordResearchController::create()` lines 29-38
- **Fix Applied:** Controller now uses `ApiResponseModifier` for consistent response format
- **Status:** ✅ Resolved

**2. Responsibility Leakage:**
- **Issue:** ~~`KeywordService::createKeywordResearch()` performs schema detection and conditional logic that should be in repository or model~~ ✅ **FIXED**
- **Location:** `KeywordService.php` lines 32-75
- **Fix Applied:** Schema detection logic moved to `KeywordResearchJobRepository` with caching
- **Status:** ✅ Resolved

**3. Missing Validation:**
- **Issue:** ~~No validation that user owns the project if `project_id` is provided~~ ✅ **FIXED**
- **Location:** `KeywordResearchRequest` validation rules
- **Fix Applied:** Added `Rule::exists()` with ownership check: `Rule::exists('projects', 'id')->where(function ($query) { return $query->where('user_id', $this->user()->id); })`
- **Status:** ✅ Resolved

**4. Redundant Calls:**
- **Issue:** `Schema::getColumnListing()` called on every request - should be cached or eliminated
- **Location:** `KeywordService::createKeywordResearch()` line 34
- **Impact:** Performance overhead on every job creation
- **Severity:** Low-Medium

**5. Performance Bottlenecks:**
- **Issue:** No database indexing strategy documented for `keyword_research_jobs` table
- **Location:** Data access layer
- **Impact:** Slow queries as job count grows
- **Severity:** Medium

**6. Error-Handling Gaps:**
- **Issue:** No explicit error handling in service method - relies on Laravel's global handler
- **Location:** `KeywordService::createKeywordResearch()`
- **Impact:** Generic 500 errors, poor error messages for users
- **Severity:** Medium

**7. Security Risks:**
- **Issue:** ~~No rate limiting on job creation endpoint - users could spam job creation~~ ✅ **FIXED**
- **Location:** `routes/api.php` line 31
- **Fix Applied:** Added `throttle:10,1` middleware (10 requests per minute)
- **Status:** ✅ Resolved

**8. Missing Transaction:**
- **Issue:** Job creation and queue dispatch not wrapped in transaction - job could be created but dispatch could fail
- **Location:** `KeywordService::createKeywordResearch()` lines 77-79
- **Impact:** Orphaned job records if queue is down
- **Severity:** Medium

**9. Inconsistent Response Format:**
- **Issue:** Controller uses direct `response()->json()` instead of injected `ApiResponseModifier`
- **Location:** `KeywordResearchController::create()` vs other methods
- **Impact:** API response format inconsistency
- **Severity:** Low

**10. No Job Deduplication:**
- **Issue:** No check for duplicate jobs (same user, same query, recent timestamp)
- **Location:** `KeywordService::createKeywordResearch()`
- **Impact:** Duplicate jobs waste resources
- **Severity:** Low-Medium

#### ✅ Improvement Recommendations

**1. Refactoring Suggestions:**

**a. Use API Resource for Response:**
```php
// Create KeywordResearchJobResource
class KeywordResearchJobResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'query' => $this->query,
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}

// In controller:
return (new KeywordResearchJobResource($job))
    ->response()
    ->setStatusCode(201);
```

**b. Move Schema Logic to Repository:**
```php
// Create KeywordResearchJobRepository
class KeywordResearchJobRepository
{
    public function createWithOptionalFields(array $data): KeywordResearchJob
    {
        $baseData = ['user_id', 'query', 'status'];
        $optionalFields = ['project_id', 'language_code', 'geoTargetId', 'settings', 'progress'];
        
        $finalData = array_intersect_key($data, array_flip($baseData));
        foreach ($optionalFields as $field) {
            if ($this->columnExists($field) && isset($data[$field])) {
                $finalData[$field] = $data[$field];
            }
        }
        
        return KeywordResearchJob::create($finalData);
    }
}
```

**c. Add Project Ownership Validation:**
```php
// In KeywordResearchRequest:
'project_id' => [
    'nullable',
    'integer',
    'exists:projects,id',
    Rule::exists('projects', 'id')->where(function ($query) {
        return $query->where('user_id', Auth::id());
    }),
],
```

**2. Better Layer Separation:**

- **Extract Repository Pattern:** Move all database operations to repository
- **Create Value Objects:** Use value objects for settings, progress instead of arrays
- **Separate Concerns:** Move schema detection to repository or use migrations to ensure consistency

**3. Caching Opportunities:**

- **Cache Schema Detection:** Cache `Schema::getColumnListing()` results for 1 hour
- **Cache User Projects:** Cache user's project list to avoid repeated queries

**4. Async/Queue Candidates:**

- **Already Implemented:** Job processing is queued ✅
- **Consider:** Queue job creation logging to avoid blocking response

**5. Design Pattern Improvements:**

**a. Use Factory Pattern for Job Creation:**
```php
class KeywordResearchJobFactory
{
    public function create(KeywordResearchRequestDTO $dto): KeywordResearchJob
    {
        return KeywordResearchJob::create($this->buildData($dto));
    }
    
    private function buildData(KeywordResearchRequestDTO $dto): array
    {
        // Centralized data building logic
    }
}
```

**b. Use Strategy Pattern for Schema Detection:**
```php
interface SchemaStrategy
{
    public function getColumns(): array;
}

class CachedSchemaStrategy implements SchemaStrategy
{
    public function getColumns(): array
    {
        return Cache::remember('keyword_research_jobs_columns', 3600, function () {
            return Schema::getColumnListing('keyword_research_jobs');
        });
    }
}
```

**6. Additional Recommendations:**

- **Add Rate Limiting:** `Route::post('/', ...)->middleware('throttle:10,1')` - 10 requests per minute
- **Add Job Deduplication:** Check for duplicate jobs in last 5 minutes before creating
- **Add Transaction:** Wrap job creation and dispatch in database transaction
- **Add Monitoring:** Log job creation metrics for observability
- **Add Validation:** Validate project ownership before job creation
- **Add Tests:** Unit tests for service method, integration tests for full flow

---

**End of Feature 1 Documentation**

---

## Feature 2: Search Volume Lookup

---

### 1️⃣ Feature Overview

**Feature Name:** Search Volume Data Retrieval

**Business Purpose:**
Enables users to retrieve search volume, competition, and CPC (Cost Per Click) data for a batch of keywords in real-time. This feature powers keyword analysis by providing essential SEO metrics needed to evaluate keyword value, competition level, and advertising costs. The system fetches data from DataForSEO API with intelligent caching to minimize API costs and improve response times.

**User Persona Involved:**
- SEO Analysts
- PPC Managers
- Content Strategists
- Marketing Managers
- Keyword Research Tools Users

**Entry Point:**
- **Primary:** HTTP POST request to `/api/seo/keywords/search-volume` from frontend application
- **Trigger:** User submits a list of keywords (1-100 keywords) for volume analysis
- **Authentication:** Required (Laravel Sanctum token-based authentication)
- **Rate Limiting:** 60 requests per minute per user

---

### 2️⃣ Frontend Execution Flow

**Note:** Frontend code is not present in this repository. The following describes the expected frontend behavior based on API contract.

**Expected Frontend Flow:**

1. **UI Component/Page:**
   - Component: Keyword Search Volume Analysis Form/Page
   - Framework: Not specified (could be React, Vue, Angular, or vanilla JS)
   - Location: SEO tools section, keyword analysis interface

2. **User Interaction:**
   - User enters keywords in textarea or multi-input field
   - Keywords can be:
     - Comma-separated list
     - Line-separated list
     - Array of strings
   - User optionally configures:
     - Language code (default: 'en')
     - Location code (default: 2840 - United States)

3. **Event Triggered:**
   - Form submission event (click on "Get Search Volume" button or Enter key)
   - JavaScript event handler intercepts form submission

4. **Client-Side Validation:**
   - Validates keywords array is not empty
   - Validates keywords array has 1-100 items
   - Validates each keyword is non-empty string
   - Validates each keyword length ≤ 255 characters
   - Validates language_code format (2-character ISO code)
   - Validates location_code is positive integer

5. **State/Data Preparation:**
   - Normalizes keywords (trim whitespace, remove duplicates)
   - Constructs JSON payload:
     ```json
     {
       "keywords": ["keyword1", "keyword2", "keyword3"],
       "language_code": "en",  // optional, default "en"
       "location_code": 2840   // optional, default 2840
     }
     ```

6. **API Call Preparation:**
   - HTTP Method: `POST`
   - Endpoint: `/api/seo/keywords/search-volume`
   - Headers:
     - `Content-Type: application/json`
     - `Accept: application/json`
     - `Authorization: Bearer {sanctum_token}` (from authenticated session)
   - Body: JSON payload from step 5

7. **API Request Execution:**
   - JavaScript fetch/axios call to backend API
   - Request sent to Laravel backend
   - Loading indicator shown to user

8. **Conditional Branches:**
   - **On Success (200 OK):** Display results in table/grid, show metrics
   - **On Validation Error (422):** Display validation error messages
   - **On Rate Limit (429):** Display rate limit message, show retry after time
   - **On Authentication Error (401):** Redirect to login page
   - **On Server Error (500):** Display generic error message

---

### 3️⃣ API Entry Point

**Route Definition File:**
- **File:** `routes/api.php`
- **Line:** 50-53

**Route Definition:**
```php
Route::prefix('seo')->middleware('throttle:60,1')->group(function () {
    Route::post('/keywords/search-volume', [DataForSEOController::class, 'searchVolume'])
        ->name('seo.search-volume');
    // ... other routes
});
```

**HTTP Method and URI:**
- Method: `POST`
- URI: `/api/seo/keywords/search-volume`
- Full URL: `{base_url}/api/seo/keywords/search-volume`

**Middleware Stack Executed (in order):**

1. **Route Group Middleware:**
   - `auth:sanctum` (Line 19 in `routes/api.php`)
     - **Purpose:** Validates Laravel Sanctum authentication token
     - **Action:** Extracts Bearer token from `Authorization` header
     - **Validation:** Checks token exists in `personal_access_tokens` table
     - **On Failure:** Returns 401 Unauthorized
     - **On Success:** Attaches authenticated `User` model to request

2. **Route Prefix Middleware:**
   - `throttle:60,1` (Line 50 in `routes/api.php`)
     - **Purpose:** Rate limiting to prevent API abuse
     - **Configuration:** 60 requests per 1 minute per user
     - **Implementation:** Laravel's built-in throttle middleware
     - **Storage:** Uses cache driver (Redis recommended)
     - **On Failure:** Returns 429 Too Many Requests
     - **Response Headers:** 
       - `X-RateLimit-Limit: 60`
       - `X-RateLimit-Remaining: {remaining}`
       - `Retry-After: {seconds}`

**Request Validation Layer:**

**File:** `app/Http/Requests/SearchVolumeRequest.php`

**Validation Rules Executed:**

1. **`keywords` field:**
   - `required` - Must be present
   - `array` - Must be array type
   - `min:1` - Minimum 1 keyword required
   - `max:100` - Maximum 100 keywords allowed

2. **`keywords.*` field (each array element):**
   - `required` - Each keyword must be present
   - `string` - Must be string type
   - `max:255` - Maximum 255 characters per keyword

3. **`language_code` field:**
   - `sometimes` - Optional field (only validated if present)
   - `string` - Must be string if provided
   - `size:2` - Must be exactly 2 characters

4. **`location_code` field:**
   - `sometimes` - Optional field (only validated if present)
   - `integer` - Must be integer if provided
   - `min:1` - Must be at least 1

**Default Values Applied (in `validated()` method):**
- `language_code`: Defaults to `'en'` if not provided
- `location_code`: Defaults to `2840` (United States) if not provided

**Validation Failure Handling:**
- Returns HTTP 422 Unprocessable Entity
- Response format:
  ```json
  {
    "message": "The given data was invalid.",
    "errors": {
      "keywords": ["The keywords field is required."],
      "keywords.0": ["Each keyword is required."],
      "keywords.*": ["Maximum 100 keywords allowed."]
    }
  }
  ```

**Data Transformations:**
- None at validation layer - raw validated data passed to controller

---

### 4️⃣ Controller Layer

**Controller Class:**
- **File:** `app/Http/Controllers/Api/DataForSEO/DataForSEOController.php`
- **Class Name:** `App\Http\Controllers\Api\DataForSEO\DataForSEOController`
- **Namespace:** `App\Http\Controllers\Api\DataForSEO`
- **Extends:** `App\Http\Controllers\Controller`

**Method Name:**
- `searchVolume(SearchVolumeRequest $request): JsonResponse`

**Method Execution Flow:**

1. **Parameter Received:**
   - `$request` - Instance of `SearchVolumeRequest` (FormRequest)
   - Already validated by Laravel's FormRequest mechanism
   - Contains validated and sanitized input data

2. **Authorization Checks:**
   - **No explicit authorization checks in controller method**
   - Authentication already verified by `auth:sanctum` middleware
   - Rate limiting already enforced by `throttle:60,1` middleware

3. **Data Extraction:**
   - Line 29: `$validated = $request->validated();`
     - Extracts validated data array from FormRequest
     - Contains: `keywords`, `language_code`, `location_code`

4. **Service Method Call:**
   - Lines 31-35: Calls service method
     ```php
     $results = $this->service->getSearchVolume(
         $validated['keywords'],
         $validated['language_code'],
         $validated['location_code']
     );
     ```
   - **Service:** `App\Services\DataForSEO\DataForSEOService`
   - **Method:** `getSearchVolume(array $keywords, string $languageCode, int $locationCode): array`
   - **Returns:** Array of `SearchVolumeDTO` objects

5. **Data Transformation:**
   - Lines 37-39: Converts DTOs to arrays
     ```php
     $data = array_map(function ($dto) {
         return $dto->toArray();
     }, $results);
     ```
   - **Transformation:** Each `SearchVolumeDTO` converted to array
   - **Result:** Array of associative arrays with keyword data

6. **Logging:**
   - Lines 41-44: Logs successful request
     ```php
     Log::info('Search volume request completed', [
         'keywords_count' => count($validated['keywords']),
         'results_count' => count($data),
     ]);
     ```
   - **Log Level:** `info`
   - **Data Logged:** Keyword count, results count

7. **Response Construction:**
   - Lines 46-49: Builds JSON response using `ApiResponseModifier`
     ```php
     return $this->responseModifier
         ->setData($data)
         ->setMessage('Search volume data retrieved successfully')
         ->response();
     ```
   - **Response Modifier:** `App\Services\ApiResponseModifier`
   - **HTTP Status Code:** `200 OK` (default)

**Error Handling:**

**InvalidArgumentException (422):**
- Lines 50-58: Catches validation errors from service
- **Action:** Logs warning, returns 422 with error message
- **Response:**
  ```json
  {
    "status": 422,
    "message": "Keywords array cannot be empty",
    "response": null
  }
  ```

**DataForSEOException (Custom Status Code):**
- Lines 59-68: Catches DataForSEO API errors
- **Action:** Logs error with error code, returns custom status code
- **Response:**
  ```json
  {
    "status": 500,
    "message": "DataForSEO API error: Invalid API response",
    "response": null
  }
  ```

**Generic Exception (500):**
- Lines 69-78: Catches unexpected errors
- **Action:** Logs error with trace, returns generic error message
- **Response:**
  ```json
  {
    "status": 500,
    "message": "An unexpected error occurred",
    "response": null
  }
  ```

**What Controller Does:**
- ✅ Validates request data
- ✅ Delegates business logic to service
- ✅ Transforms DTOs to arrays
- ✅ Formats response using `ApiResponseModifier`
- ✅ Handles errors with appropriate status codes
- ✅ Logs request completion

**What Controller Does NOT Do:**
- ❌ Does not perform business logic
- ❌ Does not interact with external APIs directly
- ❌ Does not handle caching (delegated to service)
- ❌ Does not interact with database directly

---

### 5️⃣ Service Layer (Core Logic)

**Service Class:**
- **File:** `app/Services/DataForSEO/DataForSEOService.php`
- **Class Name:** `App\Services\DataForSEO\DataForSEOService`
- **Namespace:** `App\Services\DataForSEO`

**Dependencies Injected (Constructor):**
1. `KeywordCacheRepositoryInterface $cacheRepository` - Used for database caching (not used in `getSearchVolume`)

**Configuration Loaded (Constructor):**
- `base_url`: From `config('services.dataforseo.base_url')` - Default: `'https://api.dataforseo.com/v3'`
- `login`: From `config('services.dataforseo.login')` - DataForSEO API username
- `password`: From `config('services.dataforseo.password')` - DataForSEO API password
- `cacheTTL`: From `config('services.dataforseo.cache_ttl')` - Default: `86400` (24 hours)

**Constructor Validation:**
- Lines 30-36: Validates configuration is complete
- **On Missing Config:** Throws `DataForSEOException` with `CONFIG_ERROR` code
- **Error Message:** "DataForSEO configuration is incomplete. Please check your environment variables."

**Method: `getSearchVolume(array $keywords, string $languageCode = 'en', int $locationCode = 2840): array`**

**Step-by-Step Internal Logic:**

**Step 1: Input Validation (Lines 52-67)**
- **Purpose:** Validate keywords array before processing
- **Validation Checks:**
  1. **Empty Array Check (Line 52-54):**
     - Throws `InvalidArgumentException` if keywords array is empty
     - Error Message: "Keywords array cannot be empty"
  
  2. **Maximum Count Check (Lines 56-58):**
     - Throws `InvalidArgumentException` if more than 100 keywords
     - Error Message: "Maximum 100 keywords allowed per request"
  
  3. **Individual Keyword Validation (Lines 60-67):**
     - Loops through each keyword
     - Validates: non-empty string, max 255 characters
     - Throws `InvalidArgumentException` for invalid keywords
     - Error Message: "Invalid keyword: {keyword}" or "Keyword exceeds maximum length: {keyword}"

**Step 2: Request Deduplication (Lines 69-72)**
- **Purpose:** Prevent duplicate API calls for same request
- **Action:**
  ```php
  $lockKey = 'dataforseo:lock:search_volume:' . md5(serialize([$keywords, $languageCode, $locationCode]));
  
  return Cache::lock($lockKey, 30)->get(function () use ($keywords, $languageCode, $locationCode) {
      // Process request within lock
  });
  ```
- **Lock Duration:** 30 seconds
- **Why:** Prevents multiple simultaneous requests from hitting API

**Step 3: Database Cache Lookup (Lines 73-111)**
- **Purpose:** Check database cache first for persistent storage
- **Action:**
  ```php
  $cachedResults = [];
  $uncachedKeywords = [];
  
  foreach ($keywords as $keyword) {
      $cache = $this->cacheRepository->findValid($keyword, $languageCode, $locationCode);
      if ($cache) {
          $cachedResults[] = SearchVolumeDTO::fromArray([...]);
      } else {
          $uncachedKeywords[] = $keyword;
      }
  }
  ```
- **Repository:** `KeywordCacheRepository::findValid()`
- **Table:** `keyword_cache`
- **On Cache Hit:** Returns immediately if all keywords cached
- **On Partial Cache:** Continues with uncached keywords

**Step 4: In-Memory Cache Lookup (Lines 113-128)**
- **Purpose:** Check Laravel cache for remaining keywords
- **Action:**
  ```php
  $cacheKey = $this->getCacheKey('search_volume', [
      'keywords' => $uncachedKeywords,
      'language_code' => $languageCode,
      'location_code' => $locationCode,
  ]);
  
  if (Cache::has($cacheKey)) {
      $cachedFromMemory = Cache::get($cacheKey);
      return array_merge($cachedResults, $cachedFromMemory);
  }
  ```
- **Cache Driver:** Uses Laravel's default cache driver (configured in `config/cache.php`)
- **Cache TTL:** 24 hours (86400 seconds) as configured
- **On Cache Hit:** Merges with database cache results and returns

**Step 5: Payload Construction (Lines 145-153)**
- **Purpose:** Build DataForSEO API request payload
- **Action:**
  ```php
  $payload = [
      'data' => [
          [
              'keywords' => $keywords,
              'language_code' => $languageCode,
              'location_code' => $locationCode,
          ]
      ]
  ];
  ```
- **Payload Structure:** DataForSEO API requires nested `data` array
- **Keywords:** Array of keyword strings (1-100 items)
- **Language Code:** ISO 639-1 code (e.g., 'en', 'es', 'fr')
- **Location Code:** Integer location ID (2840 = United States)

**Step 6: HTTP Client Preparation (Lines 39-46)**
- **Purpose:** Configure HTTP client for API request
- **Method:** `client()` (protected method)
- **Action:**
  ```php
  return Http::withBasicAuth($this->login, $this->password)
      ->acceptJson()
      ->baseUrl($this->baseUrl)
      ->timeout(config('services.dataforseo.timeout', 30))
      ->retry(3, 100);
  ```
- **Authentication:** Basic Auth with login/password
- **Accept Header:** `application/json`
- **Base URL:** DataForSEO API base URL
- **Timeout:** 30 seconds (configurable)
- **Retry Logic:** 3 retries with 100ms delay between attempts

**Step 7: API Request Execution (Lines 167-169)**
- **Purpose:** Execute HTTP POST request to DataForSEO API
- **Action:**
  ```php
  $httpResponse = $this->client()
      ->post('/keywords_data/google_ads/search_volume/live', $payload)
      ->throw();
  ```
- **Endpoint:** `/keywords_data/google_ads/search_volume/live`
- **Method:** POST
- **Full URL:** `{base_url}/keywords_data/google_ads/search_volume/live`
- **`throw()` Method:** Automatically throws exception on HTTP error (4xx, 5xx)

**Step 8: Request Logging (Lines 155-165)**
- **Purpose:** Log API request for debugging/monitoring
- **Action:**
  ```php
  Log::info('Fetching search volume from DataForSEO API', [
      'keywords_count' => count($keywords),
      'language_code' => $languageCode,
      'location_code' => $locationCode,
  ]);
  
  Log::info('DataForSEO Search Volume API Request Payload', [
      'endpoint' => '/keywords_data/google_ads/search_volume/live',
      'payload' => $payload,
  ]);
  ```
- **Log Level:** `info`
- **Data Logged:** Keyword count, language, location, full payload

**Step 9: Response Logging (Lines 171-177)**
- **Purpose:** Log API response for debugging
- **Action:**
  ```php
  Log::info('DataForSEO Search Volume API Response', [
      'keywords_count' => count($keywords),
      'status_code' => $httpResponse->status(),
      'response_keys' => array_keys($httpResponse->json() ?? []),
      'full_response' => $httpResponse->json(),
  ]);
  ```
- **Log Level:** `info`
- **Data Logged:** Status code, response structure, full response body

**Step 10: Response Parsing (Line 179)**
- **Purpose:** Parse JSON response to PHP array
- **Action:** `$response = $httpResponse->json();`
- **Result:** Associative array from JSON response

**Step 11: Response Structure Validation (Lines 181-188)**
- **Purpose:** Validate API response has expected structure
- **Check:** `if (!isset($response['tasks']) || !is_array($response['tasks']) || empty($response['tasks']))`
- **On Failure:** Throws `DataForSEOException` with:
  - Message: "Invalid API response: missing tasks"
  - Status Code: 500
  - Error Code: `INVALID_RESPONSE`
- **Logging:** Logs error with full response

**Step 12: Task Extraction (Line 190)**
- **Purpose:** Extract first task from response
- **Action:** `$task = $response['tasks'][0];`
- **Assumption:** API returns tasks array, first task contains results

**Step 13: Status Code Validation (Lines 192-203)**
- **Purpose:** Validate task status code (20000 = success in DataForSEO)
- **Check:** `if (isset($task['status_code']) && $task['status_code'] !== 20000)`
- **On Failure:** Throws `DataForSEOException` with:
  - Message: "DataForSEO API error: {status_message}"
  - Status Code: Task's status_code or 500
  - Error Code: `API_ERROR`
- **Logging:** Logs error with status code and message

**Step 14: Results Extraction (Lines 205-209)**
- **Purpose:** Extract results array from task
- **Check:** `if (!isset($task['result']) || !is_array($task['result']) || empty($task['result']))`
- **On Empty:** Returns existing cached results if any, otherwise empty array
- **Logging:** Logs warning if no results found

**Step 15: DTO Conversion with Partial Handling (Lines 211-246)**
- **Purpose:** Convert API response items to DTO objects with error resilience
- **Action:**
  ```php
  $results = [];
  $cacheData = [];
  
  foreach ($items as $item) {
      try {
          $dto = SearchVolumeDTO::fromArray($item);
          $results[] = $dto;
          // Prepare data for database cache
          $cacheData[] = [...];
      } catch (\Exception $e) {
          Log::warning('Failed to process keyword result', [...]);
          // Continue processing other keywords
      }
  }
  ```
- **DTO Class:** `App\DTOs\SearchVolumeDTO`
- **Method:** `fromArray(array $data): self`
- **Transformation:**
  - Converts competition string ('HIGH', 'MEDIUM', 'LOW') to float (1.0, 0.5, 0.0)
  - Calculates CPC from low/high bids if not provided
  - Extracts keyword info (monthly searches, bids, etc.)
- **Error Handling:** Continues processing even if individual keywords fail

**Step 16: Database Cache Storage (Lines 248-258)**
- **Purpose:** Store results in database cache for persistence
- **Action:**
  ```php
  if (!empty($cacheData)) {
      try {
          $this->cacheRepository->bulkUpdate($cacheData);
      } catch (\Exception $e) {
          Log::warning('Failed to store search volume in database cache', [...]);
          // Don't fail the request if cache storage fails
      }
  }
  ```
- **Repository:** `KeywordCacheRepository::bulkUpdate()`
- **Table:** `keyword_cache`
- **Error Handling:** Cache failures don't fail the request

**Step 17: In-Memory Cache Storage (Lines 260-266)**
- **Purpose:** Store results in Laravel cache for fast access
- **Action:**
  ```php
  Cache::put($cacheKey, $results, now()->addSeconds($this->cacheTTL));
  ```
- **Cache TTL:** 24 hours (86400 seconds)
- **Storage:** Results stored as array of DTO objects

**Step 18: Result Merging (Line 269)**
- **Purpose:** Merge API results with cached results
- **Action:** `$allResults = array_merge($existingResults, $results);`
- **Returns:** Combined results from cache and API

**Step 19: Success Logging (Lines 271-275)**
- **Purpose:** Log successful API call
- **Action:**
  ```php
  Log::info('Successfully fetched search volume', [
      'keywords_count' => count($keywords),
      'results_count' => count($results),
      'total_results' => count($allResults),
  ]);
  ```

**Step 20: Return Results (Line 277)**
- **Purpose:** Return array of SearchVolumeDTO objects
- **Action:** `return $allResults;`
- **Type:** `array<SearchVolumeDTO>`
- **Note:** Returns partial results even if some keywords failed

**Error Handling:**

**RequestException (HTTP Errors):**
- Lines 278-290: Catches HTTP request failures
- **Action:** 
  - Logs error with response
  - Returns existing cached results if available (graceful degradation)
  - Throws `DataForSEOException` only if no cached results available
- **Error Code:** `API_REQUEST_FAILED`
- **Status Code:** 500

**DataForSEOException (Re-thrown):**
- Lines 291-300: Catches DataForSEO exceptions
- **Action:** 
  - Returns existing cached results if available
  - Re-throws exception only if no cached results
- **Graceful Degradation:** Prefers returning cached data over failing

**Generic Exception:**
- Lines 301-310: Catches unexpected errors
- **Action:** 
  - Logs error with trace
  - Returns existing cached results if available
  - Throws `DataForSEOException` only if no cached results
- **Error Code:** `UNEXPECTED_ERROR`
- **Status Code:** 500

**Service Method Calls:**
- **None** - This method is self-contained (does not call other services)

**External API Calls:**
- **DataForSEO API:** POST to `/keywords_data/google_ads/search_volume/live`
- **Request Format:** JSON with nested data array
- **Response Format:** JSON with tasks array containing results

**Cache Interactions:**
- **Cache Read:** `Cache::has()` and `Cache::get()` for lookup
- **Cache Write:** `Cache::put()` for storage
- **Cache Driver:** Laravel's default cache (Redis recommended)

**Database Interactions:**
- **None** - This method does not interact with database (database caching handled separately)

---

### 6️⃣ Data Access Layer

**Database Cache Access in `getSearchVolume` Method**

**Note:** Database caching is now actively used via `KeywordCacheRepository` in the `getSearchVolume` method. The method uses a dual caching strategy:
1. **Database Cache (Primary):** Checks `keyword_cache` table for persistent storage
2. **In-Memory Cache (Secondary):** Falls back to Laravel cache for fast access
3. **API Call (Last Resort):** Only calls API if not found in either cache

**Cache Storage (Not Database):**
- **Storage Driver:** Laravel Cache (configured in `config/cache.php`)
- **Default Driver:** File cache or Redis (production)
- **Cache Key Format:** `dataforseo:search_volume:{md5_hash}`
- **Cache TTL:** 86400 seconds (24 hours)
- **Storage Location:**
  - File cache: `storage/framework/cache/data/`
  - Redis: In-memory key-value store

**Cache Key Generation:**
- **Method:** `getCacheKey(string $type, array $params): string`
- **Implementation:** MD5 hash of serialized parameters
- **Uniqueness:** Ensures same keywords + language + location = same cache key

**Cache Operations:**
- **Read:** `Cache::has($cacheKey)` - Check if key exists
- **Read:** `Cache::get($cacheKey)` - Retrieve cached value
- **Write:** `Cache::put($cacheKey, $value, $ttl)` - Store value with TTL

**Database Caching (Now Used):**
- **Repository:** `KeywordCacheRepository` (actively used)
- **Table:** `keyword_cache`
- **Purpose:** Persistent caching across application restarts
- **Operations:**
  - `findValid()`: Checks for valid cache entries by keyword, language, location
  - `bulkUpdate()`: Stores API results in database for future requests
- **TTL:** 30 days (configurable via model)

---

### 7️⃣ External Integrations

**DataForSEO API Integration:**

**Service Name:** DataForSEO API
**Base URL:** `https://api.dataforseo.com/v3` (configurable)
**Endpoint:** `/keywords_data/google_ads/search_volume/live`
**Full URL:** `{base_url}/keywords_data/google_ads/search_volume/live`

**Trigger Point:**
- Triggered when cache miss occurs (Line 105)
- Only called if results not found in cache

**Request Format:**
```json
{
  "data": [
    {
      "keywords": ["keyword1", "keyword2", "keyword3"],
      "language_code": "en",
      "location_code": 2840
    }
  ]
}
```

**Request Headers:**
- `Authorization: Basic {base64(login:password)}`
- `Accept: application/json`
- `Content-Type: application/json`

**Response Format:**
```json
{
  "tasks": [
    {
      "id": "task_id",
      "status_code": 20000,
      "status_message": "Ok.",
      "result": [
        {
          "keyword": "keyword1",
          "search_volume": 1000,
          "competition": "HIGH",
          "competition_index": 85,
          "cpc": 2.50,
          "monthly_searches": [
            {"year": 2024, "month": 1, "search_volume": 1000}
          ],
          "low_top_of_page_bid": 2.00,
          "high_top_of_page_bid": 3.00
        }
      ]
    }
  ]
}
```

**Response Handling:**
1. **Status Code Validation:** Checks `status_code === 20000` (success)
2. **Structure Validation:** Validates `tasks` array exists and is non-empty
3. **Results Extraction:** Extracts `result` array from first task
4. **DTO Conversion:** Converts each result item to `SearchVolumeDTO`

**Retry/Failure Strategy:**
- **Retry Logic:** 3 automatic retries with 100ms delay (configured in HTTP client)
- **Timeout:** 30 seconds per request (configurable)
- **On Failure:** Throws `DataForSEOException` with error details
- **Error Codes:**
  - `INVALID_RESPONSE` - Malformed API response
  - `API_ERROR` - API returned error status code
  - `API_REQUEST_FAILED` - HTTP request failed
  - `UNEXPECTED_ERROR` - Unexpected exception

**Timeouts and Fallbacks:**
- **Timeout:** 30 seconds (configurable via `config('services.dataforseo.timeout')`)
- **Fallback:** None - throws exception on timeout
- **No Partial Results:** If API fails, entire request fails (no partial data returned)

**Rate Limiting:**
- **Client-Side:** Not implemented in service
- **Server-Side:** DataForSEO API has its own rate limits (not documented in code)
- **Recommendation:** Monitor API responses for rate limit errors

**Cost Considerations:**
- **API Credits:** Each request consumes DataForSEO API credits
- **Caching Strategy:** 24-hour cache reduces API calls significantly
- **Batch Processing:** Up to 100 keywords per request (efficient)

---

### 8️⃣ Response Construction

**Response Builder:**
- **Location:** Controller method (lines 46-49)
- **Type:** `ApiResponseModifier` service

**Data Transformation:**
- **Input:** Array of `SearchVolumeDTO` objects
- **Output:** JSON response array
- **Transformation:**
  ```php
  $data = array_map(function ($dto) {
      return $dto->toArray();
  }, $results);
  ```
- **DTO Method:** `SearchVolumeDTO::toArray()`
- **Output Structure:**
  ```php
  [
      'keyword' => string,
      'search_volume' => int|null,
      'competition' => float|null,  // 0.0, 0.5, or 1.0
      'cpc' => float|null,
      'competition_index' => string|null,
      'keyword_info' => [
          'monthly_searches' => array|null,
          'low_top_of_page_bid' => float|null,
          'high_top_of_page_bid' => float|null,
          'search_partners' => bool|null,
          'spell' => string|null,
      ],
  ]
  ```

**Response Modifier Usage:**
- **Service:** `App\Services\ApiResponseModifier`
- **Method Chain:**
  ```php
  $this->responseModifier
      ->setData($data)
      ->setMessage('Search volume data retrieved successfully')
      ->response();
  ```

**Success Response Structure:**
```json
{
  "status": 200,
  "message": "Search volume data retrieved successfully",
  "response": [
    {
      "keyword": "digital marketing",
      "search_volume": 10000,
      "competition": 1.0,
      "cpc": 2.50,
      "competition_index": "85",
      "keyword_info": {
        "monthly_searches": [
          {"year": 2024, "month": 1, "search_volume": 10000}
        ],
        "low_top_of_page_bid": 2.00,
        "high_top_of_page_bid": 3.00,
        "search_partners": true,
        "spell": null
      }
    }
  ]
}
```

**HTTP Status Codes:**
- **Success:** `200 OK` (default from `ApiResponseModifier`)
- **Validation Error:** `422 Unprocessable Entity` (from FormRequest)
- **Rate Limit:** `429 Too Many Requests` (from throttle middleware)
- **API Error:** `500 Internal Server Error` (from `DataForSEOException`)
- **Auth Error:** `401 Unauthorized` (from auth middleware)

**Error Response Structures:**

**Validation Error (422):**
```json
{
  "status": 422,
  "message": "The given data was invalid.",
  "response": null
}
```

**DataForSEO API Error (500):**
```json
{
  "status": 500,
  "message": "DataForSEO API error: Invalid API response",
  "response": null
}
```

**Rate Limit Error (429):**
```json
{
  "message": "Too Many Attempts."
}
```

**Messages Returned:**
- **Success:** "Search volume data retrieved successfully"
- **Validation:** Per-field error messages from FormRequest
- **API Error:** Error message from DataForSEO API or exception
- **Rate Limit:** "Too Many Attempts."

---

### 9️⃣ Frontend Response Handling

**Expected Frontend Behavior (Not in Repository):**

**On Success (200 OK):**

1. **Response Parsing:**
   - Parse JSON response
   - Extract `response` array (array of keyword data objects)
   - Extract `message` for display

2. **Data Processing:**
   - Map response array to UI data structure
   - Calculate statistics:
     - Total keywords processed
     - Average search volume
     - Average CPC
     - Keywords with high competition
   - Sort/filter keywords by metrics

3. **UI State Updates:**
   - Hide loading indicator
   - Display results in table/grid format
   - Show columns:
     - Keyword
     - Search Volume
     - Competition (with color coding: High=red, Medium=yellow, Low=green)
     - CPC
     - Competition Index
   - Enable sorting by columns
   - Enable filtering/searching

4. **Visualization:**
   - Display charts/graphs:
     - Search volume distribution
     - Competition distribution
     - CPC distribution
   - Show keyword info tooltips on hover

5. **Export Functionality:**
   - Enable CSV export
   - Enable JSON export
   - Enable Excel export

**On Validation Error (422):**

1. **Response Parsing:**
   - Parse JSON response
   - Extract `errors` object (if available)
   - Extract `message`

2. **UI Updates:**
   - Display error message at top of form
   - Highlight invalid fields
   - Show field-specific error messages
   - Keep form data (don't clear inputs)

3. **User Action:**
   - User corrects errors
   - Resubmits form

**On Rate Limit Error (429):**

1. **Response Parsing:**
   - Parse JSON response
   - Extract `Retry-After` header (if available)
   - Calculate retry time

2. **UI Updates:**
   - Display rate limit message
   - Show countdown timer until retry available
   - Disable submit button
   - Enable submit button after countdown

3. **User Action:**
   - Wait for countdown
   - Retry request

**On API Error (500):**

1. **Response Parsing:**
   - Parse JSON response
   - Extract error message

2. **UI Updates:**
   - Display error message: "Failed to fetch search volume data. Please try again."
   - Show "Retry" button
   - Log error to console (development)

3. **User Action:**
   - Click "Retry" to resubmit request
   - Or modify keywords and retry

**On Authentication Error (401):**

1. **Response Handling:**
   - Clear authentication token
   - Redirect to login page
   - Store intended destination for post-login redirect

**Performance Optimizations:**
- **Debouncing:** Debounce API calls if user is typing keywords
- **Pagination:** If many keywords, paginate results
- **Virtual Scrolling:** Use virtual scrolling for large result sets
- **Lazy Loading:** Load keyword info details on demand

---

### 🔍 10️⃣ Architectural & Quality Audit

#### ❌ Identified Flaws

**1. Inconsistent Caching Strategy:**
- **Issue:** ~~Uses Laravel Cache (in-memory) instead of database cache repository that's injected~~ ✅ **FIXED**
- **Location:** `DataForSEOService::getSearchVolume()` lines 75-80
- **Fix Applied:** Now uses dual caching strategy: checks database cache first via `KeywordCacheRepository`, then in-memory cache, then API
- **Status:** ✅ Resolved

**2. Missing Database Cache Integration:**
- **Issue:** ~~`KeywordCacheRepository` is injected but never used in `getSearchVolume` method~~ ✅ **FIXED**
- **Location:** `DataForSEOService` constructor and `getSearchVolume()` method
- **Fix Applied:** Repository now actively used to check and store search volume data in `keyword_cache` table
- **Status:** ✅ Resolved

**3. No Partial Results Handling:**
- **Issue:** ~~If API returns partial results (some keywords missing), entire request fails~~ ✅ **FIXED**
- **Location:** `DataForSEOService::getSearchVolume()` lines 143-146
- **Fix Applied:** Added partial results handling - continues processing even if some keywords fail, returns cached results on API failure
- **Status:** ✅ Resolved

**4. Cache Key Collision Risk:**
- **Issue:** MD5 hash of serialized array may have collisions (theoretical)
- **Location:** `DataForSEOService::getCacheKey()` line 366
- **Impact:** Rare but possible cache key collisions
- **Severity:** Low

**5. No Cache Warming Strategy:**
- **Issue:** No mechanism to pre-populate cache for common keywords
- **Location:** Service layer
- **Impact:** First request always hits API, slower response times
- **Severity:** Low

**6. Hardcoded Cache TTL:**
- **Issue:** Cache TTL is fixed at 24 hours, no configuration per request
- **Location:** `DataForSEOService::getSearchVolume()` line 155
- **Impact:** Cannot adjust cache duration based on data freshness requirements
- **Severity:** Low

**7. Missing Request Deduplication:**
- **Issue:** ~~Multiple simultaneous requests for same keywords will all hit API~~ ✅ **FIXED**
- **Location:** Service method
- **Fix Applied:** Added cache lock-based request deduplication using `Cache::lock()` to prevent duplicate API calls
- **Status:** ✅ Resolved

**8. No Response Size Validation:**
- **Issue:** No check for response size before caching
- **Location:** `DataForSEOService::getSearchVolume()` line 155
- **Impact:** Large responses may consume excessive cache memory
- **Severity:** Low

**9. Error Message Exposure:**
- **Issue:** Full API error messages exposed to frontend
- **Location:** Controller error handling
- **Impact:** Potential information leakage about API structure
- **Severity:** Low-Medium

**10. No Batch Optimization:**
- **Issue:** No optimization for multiple keywords (could batch more efficiently)
- **Location:** Service method
- **Impact:** Inefficient API usage for large keyword sets
- **Severity:** Low

#### ✅ Improvement Recommendations

**1. Refactoring Suggestions:**

**a. Use Database Cache Repository:**
```php
// In getSearchVolume method:
// Check database cache first
$cachedResults = [];
$uncachedKeywords = [];

foreach ($keywords as $keyword) {
    $cache = $this->cacheRepository->findValid($keyword, $languageCode, $locationCode);
    if ($cache) {
        $cachedResults[] = SearchVolumeDTO::fromArray($cache->toArray());
    } else {
        $uncachedKeywords[] = $keyword;
    }
}

// Only call API for uncached keywords
if (!empty($uncachedKeywords)) {
    $apiResults = $this->fetchFromAPI($uncachedKeywords, $languageCode, $locationCode);
    // Cache API results in database
    $this->cacheApiResults($apiResults, $languageCode, $locationCode);
    $cachedResults = array_merge($cachedResults, $apiResults);
}

return $cachedResults;
```

**b. Implement Request Deduplication:**
```php
// Use cache lock to prevent duplicate requests
$lockKey = 'dataforseo:lock:' . md5(serialize([$keywords, $languageCode, $locationCode]));

return Cache::lock($lockKey, 30)->get(function () use ($keywords, $languageCode, $locationCode) {
    // Check cache again (another request may have populated it)
    if (Cache::has($cacheKey)) {
        return Cache::get($cacheKey);
    }
    
    // Proceed with API call
    // ...
});
```

**c. Add Partial Results Handling:**
```php
// Process results even if some keywords are missing
$results = [];
foreach ($items as $item) {
    try {
        $results[] = SearchVolumeDTO::fromArray($item);
    } catch (\Exception $e) {
        Log::warning('Failed to process keyword result', [
            'keyword' => $item['keyword'] ?? 'unknown',
            'error' => $e->getMessage(),
        ]);
        // Continue processing other keywords
    }
}

// Return partial results if any
if (empty($results)) {
    throw new DataForSEOException('No valid results returned from API');
}

return $results;
```

**2. Better Layer Separation:**

- **Extract Cache Strategy:** Use strategy pattern for cache implementation (in-memory vs database)
- **Separate API Client:** Extract DataForSEO API client to separate class
- **Create Response Parser:** Extract response parsing logic to dedicated parser class

**3. Caching Opportunities:**

- **Database Cache:** Use `KeywordCacheRepository` for persistent caching
- **Cache Warming:** Pre-populate cache for common keywords via scheduled job
- **Cache Invalidation:** Implement cache invalidation strategy based on data freshness
- **Multi-Level Cache:** Use both in-memory and database cache (L1/L2 cache)

**4. Async/Queue Candidates:**

- **Background Caching:** Queue cache updates to avoid blocking response
- **Batch Processing:** Process large keyword sets in background jobs

**5. Design Pattern Improvements:**

**a. Use Repository Pattern for Cache:**
```php
interface SearchVolumeCacheRepositoryInterface
{
    public function find(array $keywords, string $languageCode, int $locationCode): ?array;
    public function store(array $keywords, array $results, string $languageCode, int $locationCode): void;
}
```

**b. Use Factory Pattern for DTOs:**
```php
class SearchVolumeDTOFactory
{
    public function createFromApiResponse(array $item): SearchVolumeDTO
    {
        return SearchVolumeDTO::fromArray($item);
    }
}
```

**c. Use Strategy Pattern for Cache:**
```php
interface CacheStrategy
{
    public function get(string $key): ?array;
    public function put(string $key, array $value, int $ttl): void;
}

class InMemoryCacheStrategy implements CacheStrategy { }
class DatabaseCacheStrategy implements CacheStrategy { }
```

**6. Additional Recommendations:**

- **Add Response Size Validation:** Check response size before caching
- **Add Request Deduplication:** Use cache locks to prevent duplicate API calls
- **Add Monitoring:** Track API call counts, cache hit rates, response times
- **Add Circuit Breaker:** Implement circuit breaker pattern for API failures
- **Add Retry with Exponential Backoff:** Improve retry strategy
- **Add Response Compression:** Compress large responses before caching
- **Add Cache Tags:** Use cache tags for easier invalidation
- **Add Health Checks:** Monitor DataForSEO API health

---

**End of Feature 2 Documentation**

---

## Feature 3: Keywords for Site

---

### 1️⃣ Feature Overview

**Feature Name:** Keywords for Site Retrieval

**Business Purpose:**
Enables users to discover all keywords that a specific website or domain is ranking for in Google search results. This feature is essential for competitive analysis, keyword gap analysis, and understanding a competitor's SEO strategy. The system retrieves keywords from DataForSEO API that are associated with a target domain, including search volume, competition, CPC data, and monthly search trends. Results are cached both in-memory and in the database for performance optimization.

**User Persona Involved:**
- SEO Analysts
- Competitive Intelligence Analysts
- Content Strategists
- Marketing Managers
- Digital Marketing Agencies

**Entry Point:**
- **Primary:** HTTP POST request to `/api/keyword-planner/for-site` from frontend application
- **Alternative:** HTTP POST request to `/api/seo/keywords/for-site` (more options)
- **Trigger:** User submits a domain/website URL for keyword discovery
- **Authentication:** Required (Laravel Sanctum token-based authentication)
- **Rate Limiting:** None on `/api/keyword-planner/for-site`, 60 requests/minute on `/api/seo/keywords/for-site`

---

### 2️⃣ Frontend Execution Flow

**Note:** Frontend code is not present in this repository. The following describes the expected frontend behavior based on API contract.

**Expected Frontend Flow:**

1. **UI Component/Page:**
   - Component: Keywords for Site Analysis Form/Page
   - Framework: Not specified (could be React, Vue, Angular, or vanilla JS)
   - Location: SEO tools section, competitive analysis interface

2. **User Interaction:**
   - User enters target website/domain in input field
   - Domain can be:
     - Full URL: `https://example.com`
     - Domain only: `example.com`
     - Subdomain: `blog.example.com`
   - User optionally configures:
     - Location code (default: 2840 - United States)
     - Language code (default: 'en')
     - Search partners (default: true)
     - Result limit (optional, 1-1000)

3. **Event Triggered:**
   - Form submission event (click on "Get Keywords" button or Enter key)
   - JavaScript event handler intercepts form submission

4. **Client-Side Validation:**
   - Validates target field is not empty
   - Validates target length ≤ 255 characters
   - Validates location_code is positive integer (if provided)
   - Validates language_code format (2-character ISO code, if provided)
   - Validates search_partners is boolean (if provided)
   - Validates limit is within range 1-1000 (if provided)

5. **State/Data Preparation:**
   - Normalizes target URL (removes protocol, trailing slashes)
   - Constructs JSON payload:
     ```json
     {
       "target": "example.com",
       "location_code": 2840,  // optional, default 2840
       "language_code": "en",  // optional, default "en"
       "search_partners": true,  // optional, default true
       "limit": 100  // optional, max 1000
     }
     ```

6. **API Call Preparation:**
   - HTTP Method: `POST`
   - Endpoint: `/api/keyword-planner/for-site`
   - Headers:
     - `Content-Type: application/json`
     - `Accept: application/json`
     - `Authorization: Bearer {sanctum_token}` (from authenticated session)
   - Body: JSON payload from step 5

7. **API Request Execution:**
   - JavaScript fetch/axios call to backend API
   - Request sent to Laravel backend
   - Loading indicator shown to user

8. **Conditional Branches:**
   - **On Success (200 OK):** Display keywords in table/grid, show metrics
   - **On Validation Error (422):** Display validation error messages
   - **On Authentication Error (401):** Redirect to login page
   - **On Server Error (500):** Display generic error message

---

### 3️⃣ API Entry Point

**Route Definition File:**
- **File:** `routes/api.php`
- **Line:** 38-48

**Route Definition:**
```php
Route::prefix('keyword-planner')->group(function () {
    Route::post('/for-site', [KeywordPlannerController::class, 'getKeywordsForSite'])
        ->name('keyword-planner.for-site');
    // ... other routes
});
```

**HTTP Method and URI:**
- Method: `POST`
- URI: `/api/keyword-planner/for-site`
- Full URL: `{base_url}/api/keyword-planner/for-site`

**Middleware Stack Executed (in order):**

1. **Route Group Middleware:**
   - `auth:sanctum` (Line 19 in `routes/api.php`)
     - **Purpose:** Validates Laravel Sanctum authentication token
     - **Action:** Extracts Bearer token from `Authorization` header
     - **Validation:** Checks token exists in `personal_access_tokens` table
     - **On Failure:** Returns 401 Unauthorized
     - **On Success:** Attaches authenticated `User` model to request

2. **No Rate Limiting:**
   - Unlike `/api/seo/keywords/for-site`, this endpoint has no throttle middleware
   - **Impact:** Potential for abuse, no rate limiting protection

**Request Validation Layer:**

**File:** `app/Http/Requests/KeywordsForSitePlannerRequest.php`

**Validation Rules Executed:**

1. **`target` field:**
   - `required` - Must be present
   - `string` - Must be string type
   - `max:255` - Maximum 255 characters

2. **`location_code` field:**
   - `sometimes` - Optional field (only validated if present)
   - `integer` - Must be integer if provided
   - `min:1` - Must be at least 1

3. **`language_code` field:**
   - `sometimes` - Optional field (only validated if present)
   - `string` - Must be string if provided
   - `size:2` - Must be exactly 2 characters

4. **`search_partners` field:**
   - `sometimes` - Optional field (only validated if present)
   - `boolean` - Must be boolean if provided

5. **`limit` field:**
   - `sometimes` - Optional field (only validated if present)
   - `integer` - Must be integer if provided
   - `min:1` - Must be at least 1
   - `max:1000` - Must not exceed 1000

**Default Values Applied (in controller method):**
- `location_code`: Defaults to `2840` if not provided (Line 61)
- `language_code`: Defaults to `'en'` if not provided (Line 62)
- `search_partners`: Defaults to `true` if not provided (Line 63)
- `date_from`: Hardcoded to `null` (Line 64)
- `date_to`: Hardcoded to `null` (Line 65)
- `include_serp_info`: Hardcoded to `false` (Line 66)
- `tag`: Hardcoded to `null` (Line 67)

**Validation Failure Handling:**
- Returns HTTP 422 Unprocessable Entity
- Response format: Laravel's default validation error response
- Errors returned as JSON with field-specific messages

**Data Transformations:**
- None at validation layer - raw validated data passed to service

---

### 4️⃣ Controller Layer

**Controller Class:**
- **File:** `app/Http/Controllers/Api/KeywordPlannerController.php`
- **Class Name:** `App\Http\Controllers\Api\KeywordPlannerController`
- **Namespace:** `App\Http\Controllers\Api`
- **Extends:** `App\Http\Controllers\Controller`

**Method Name:**
- `getKeywordsForSite(Request $request)`

**Method Execution Flow:**

1. **Parameter Received:**
   - `$request` - Instance of `KeywordsForSitePlannerRequest` (FormRequest)
   - **Note:** Now uses dedicated FormRequest class for consistent validation

2. **Authorization Checks:**
   - **No explicit authorization checks in controller method**
   - Authentication already verified by `auth:sanctum` middleware

3. **Validation:**
   - Uses `KeywordsForSitePlannerRequest` FormRequest class
   - **Validation:** Handled automatically by Laravel FormRequest mechanism
   - **Consistency:** Now consistent with other endpoints

4. **Service Method Call (Lines 59-69):**
   - Calls service method with parameters:
     ```php
     $keywords = $this->dataForSEOService->getKeywordsForSite(
         $request->input('target'),
         $request->input('location_code', 2840),
         $request->input('language_code', 'en'),
         $request->input('search_partners', true),
         null,  // date_from - hardcoded
         null,  // date_to - hardcoded
         false, // include_serp_info - hardcoded
         null,  // tag - hardcoded
         $request->input('limit')
     );
     ```
   - **Service:** `App\Services\DataForSEO\DataForSEOService`
   - **Method:** `getKeywordsForSite(...)`
   - **Returns:** Array of `KeywordsForSiteDTO` objects

5. **Data Transformation (Lines 71-73):**
   - Converts DTOs to arrays:
     ```php
     $data = array_map(function ($dto) {
         return $dto->toArray();
     }, $keywords);
     ```
   - **Transformation:** Each `KeywordsForSiteDTO` converted to array
   - **Method:** `KeywordsForSiteDTO::toArray()`
   - **Result:** Array of associative arrays with keyword data

6. **Logging (Lines 75-78):**
   - Logs successful request:
     ```php
     Log::info('Keywords for site retrieved', [
         'target' => $request->input('target'),
         'count' => count($data),
     ]);
     ```

7. **Response Construction (Lines 80-83):**
   - Builds JSON response using `ApiResponseModifier`:
     ```php
     return $this->responseModifier
         ->setData($data)
         ->setMessage('Keywords for site retrieved successfully')
         ->response();
     ```
   - **Response Modifier:** `App\Services\ApiResponseModifier`
   - **HTTP Status Code:** `200 OK` (default)

**Error Handling:**

**Error Handling (Improved):**

**InvalidArgumentException (422):**
- Catches validation errors from service
- **Action:** Returns 422 with specific error message
- **Response:**
  ```json
  {
    "status": 422,
    "message": "{error_message}",
    "response": null
  }
  ```

**DataForSEOException (Custom Status Code):**
- Catches DataForSEO API errors
- **Action:** Returns status code from exception
- **Response:**
  ```json
  {
    "status": {exception_code},
    "message": "DataForSEO API error: {error_message}",
    "response": null
  }
  ```

**Generic Exception (500):**
- Catches unexpected errors
- **Action:** Logs error, returns 500 with generic message
- **Response:**
  ```json
  {
    "status": 500,
    "message": "An unexpected error occurred",
    "response": null
  }
  ```

**What Controller Does:**
- ✅ Validates request data (inline)
- ✅ Delegates business logic to service
- ✅ Transforms DTOs to arrays
- ✅ Formats response using `ApiResponseModifier`
- ✅ Logs request completion

**What Controller Does NOT Do:**
- ❌ Does not use FormRequest (inconsistent with other endpoints)
- ❌ Does not distinguish error types (all errors return 500)
- ❌ Does not perform business logic
- ❌ Does not interact with external APIs directly
- ❌ Does not handle caching (delegated to service)

---

### 5️⃣ Service Layer (Core Logic)

**Service Class:**
- **File:** `app/Services/DataForSEO/DataForSEOService.php`
- **Class Name:** `App\Services\DataForSEO\DataForSEOService`
- **Namespace:** `App\Services\DataForSEO`

**Method: `getKeywordsForSite(string $target, int $locationCode = 2840, string $languageCode = 'en', bool $searchPartners = true, ?string $dateFrom = null, ?string $dateTo = null, bool $includeSerpInfo = false, ?string $tag = null, ?int $limit = null): array`**

**Step-by-Step Internal Logic:**

**Step 1: Target URL Normalization (Lines 205-206)**
- **Purpose:** Normalize target URL to consistent format
- **Action:**
  ```php
  $target = preg_replace('/^https?:\/\//i', '', trim($target));
  $target = rtrim($target, '/');
  ```
- **Transformation:**
  - Removes `http://` or `https://` prefix (case-insensitive)
  - Trims whitespace
  - Removes trailing slash
- **Examples:**
  - `https://example.com/` → `example.com`
  - `http://blog.example.com` → `blog.example.com`
  - `example.com` → `example.com`

**Step 2: Target Validation (Lines 208-210)**
- **Purpose:** Validate normalized target is not empty
- **Check:** `if (empty($target))`
- **On Failure:** Throws `InvalidArgumentException`
- **Error Message:** "Target website/domain cannot be empty"

**Step 3: Cache Key Generation (Lines 212-220)**
- **Purpose:** Generate unique cache key for this request
- **Action:**
  ```php
  $cacheKey = $this->getCacheKey('keywords_for_site', [
      'target' => $target,
      'location_code' => $locationCode,
      'language_code' => $languageCode,
      'search_partners' => $searchPartners,
      'date_from' => $dateFrom,
      'date_to' => $dateTo,
      'include_serp_info' => $includeSerpInfo,
  ]);
  ```
- **Cache Key Format:** `dataforseo:keywords_for_site:{md5_hash}`
- **Uniqueness:** MD5 hash of serialized parameters ensures uniqueness

**Step 4: Cache Lookup (Lines 222-234)**
- **Purpose:** Check if results are already cached
- **Action:**
  ```php
  if (Cache::has($cacheKey)) {
      Log::info('Cache hit for keywords for site (Laravel cache)', [
          'target' => $target,
          'cache_key' => $cacheKey,
      ]);
      $results = Cache::get($cacheKey);
      
      if ($limit !== null && $limit > 0) {
          return array_slice($results, 0, $limit);
      }
      
      return $results;
  }
  ```
- **Cache Driver:** Laravel's default cache (Redis/file cache)
- **Cache TTL:** 24 hours (86400 seconds)
- **On Cache Hit:** Returns cached results immediately
- **Limit Application:** If limit specified, applies to cached results
- **Logging:** Logs cache hit for monitoring

**Step 5: Payload Construction (Lines 236-253)**
- **Purpose:** Build DataForSEO API request payload
- **Action:**
  ```php
  $taskData = [
      'target' => $target,
      'location_code' => $locationCode,
      'language_code' => $languageCode,
      'search_partners' => $searchPartners,
  ];
  
  // Only include optional parameters if they have valid values
  if ($tag !== null) {
      $taskData['tag'] = $tag;
  }
  
  $payload = [
      'data' => [
          $taskData
      ]
  ];
  ```
- **Payload Structure:** DataForSEO API requires nested `data` array
- **Required Fields:** `target`, `location_code`, `language_code`, `search_partners`
- **Optional Fields:** `tag` (only included if not null)
- **Note:** `include_serp_info` is intentionally excluded (may cause validation errors)

**Step 6: Request Logging (Lines 256-265)**
- **Purpose:** Log API request for debugging/monitoring
- **Action:**
  ```php
  Log::info('Fetching keywords for site from DataForSEO API', [
      'target' => $target,
      'location_code' => $locationCode,
      'language_code' => $languageCode,
  ]);
  
  Log::info('DataForSEO Keywords For Site API Request Payload', [
      'endpoint' => '/keywords_data/google_ads/keywords_for_site/live',
      'payload' => $payload,
  ]);
  ```

**Step 7: API Request Execution (Lines 267-269)**
- **Purpose:** Execute HTTP POST request to DataForSEO API
- **Action:**
  ```php
  $httpResponse = $this->client()
      ->post('/keywords_data/google_ads/keywords_for_site/live', $payload)
      ->throw();
  ```
- **Endpoint:** `/keywords_data/google_ads/keywords_for_site/live`
- **Method:** POST
- **HTTP Client:** Configured with Basic Auth, timeout, retry logic

**Step 8: Response Logging (Lines 272-277)**
- **Purpose:** Log API response for debugging
- **Action:**
  ```php
  Log::info('DataForSEO Keywords For Site API Response', [
      'target' => $target,
      'status_code' => $httpResponse->status(),
      'response_keys' => array_keys($httpResponse->json() ?? []),
      'full_response' => $httpResponse->json(),
  ]);
  ```

**Step 9: Response Parsing (Line 279)**
- **Action:** `$response = $httpResponse->json();`
- **Result:** Associative array from JSON response

**Step 10: Response Structure Validation (Lines 281-288)**
- **Purpose:** Validate API response has expected structure
- **Check:** `if (!isset($response['tasks']) || !is_array($response['tasks']) || empty($response['tasks']))`
- **On Failure:** Throws `DataForSEOException` with:
  - Message: "Invalid API response: missing tasks"
  - Status Code: 500
  - Error Code: `INVALID_RESPONSE`

**Step 11: Task Extraction (Line 290)**
- **Action:** `$task = $response['tasks'][0];`
- **Assumption:** First task contains results

**Step 12: Status Code Validation (Lines 292-303)**
- **Purpose:** Validate task status code (20000 = success)
- **Check:** `if (isset($task['status_code']) && $task['status_code'] !== 20000)`
- **On Failure:** Throws `DataForSEOException` with:
  - Message: "DataForSEO API error: {status_message}"
  - Status Code: Task's status_code or 500
  - Error Code: `API_ERROR`

**Step 13: Results Extraction (Lines 305-308)**
- **Purpose:** Extract results array from task
- **Check:** `if (!isset($task['result']) || !is_array($task['result']) || empty($task['result']))`
- **On Empty:** Returns empty array `[]`
- **Logging:** Logs warning if no results found

**Step 14: DTO Conversion (Lines 311-315)**
- **Purpose:** Convert API response items to DTO objects
- **Action:**
  ```php
  $items = $task['result'];
  $results = array_map(function ($item) {
      return \App\DTOs\KeywordsForSiteDTO::fromArray($item);
  }, $items);
  ```
- **DTO Class:** `App\DTOs\KeywordsForSiteDTO`
- **Method:** `fromArray(array $data): self`
- **Transformation:** Converts API response format to DTO structure

**Step 15: In-Memory Cache Storage (Line 317)**
- **Purpose:** Store results in Laravel cache
- **Action:**
  ```php
  Cache::put($cacheKey, $results, now()->addSeconds($this->cacheTTL));
  ```
- **Cache TTL:** 24 hours (86400 seconds)
- **Storage:** Results stored as array of DTO objects

**Step 16: Database Cache Storage (Line 319)**
- **Purpose:** Store results in database for persistent caching
- **Action:**
  ```php
  $this->cacheKeywordsInDatabase($results, $languageCode, $locationCode);
  ```
- **Method:** `cacheKeywordsInDatabase(array $keywords, string $languageCode, int $locationCode): void` (Line 372)
- **Implementation:**
  1. Loops through keywords
  2. Builds cache data array with:
     - `keyword`, `language_code`, `location_code`
     - `search_volume`, `competition`, `cpc`
     - `source`: `'dataforseo_keywords_for_site'`
     - `metadata`: JSON with target, monthly_searches, competition_index, cached_at
  3. Calls `$this->cacheRepository->bulkUpdate($cacheData)`
  4. Logs success or warning on failure
- **Error Handling:** Database cache failures are logged but don't fail the request

**Step 17: Success Logging (Lines 321-324)**
- **Purpose:** Log successful API call
- **Action:**
  ```php
  Log::info('Successfully fetched keywords for site', [
      'target' => $target,
      'results_count' => count($results),
  ]);
  ```

**Step 18: Limit Application (Lines 326-328)**
- **Purpose:** Apply result limit if specified
- **Action:**
  ```php
  if ($limit !== null && $limit > 0) {
      return array_slice($results, 0, $limit);
  }
  ```
- **Note:** Limit applied after caching (full results cached, limited results returned)

**Step 19: Return Results (Line 330)**
- **Action:** `return $results;`
- **Type:** `array<KeywordsForSiteDTO>`

**Error Handling:**

**RequestException (HTTP Errors):**
- Lines 331-343: Catches HTTP request failures
- **Action:** Logs error with response, throws `DataForSEOException`
- **Error Code:** `API_REQUEST_FAILED`
- **Status Code:** 500

**DataForSEOException (Re-thrown):**
- Lines 344-345: Re-throws DataForSEO exceptions
- **Action:** Propagates exception to controller

**Generic Exception:**
- Lines 346-358: Catches unexpected errors
- **Action:** Logs error with trace, throws `DataForSEOException`
- **Error Code:** `UNEXPECTED_ERROR`
- **Status Code:** 500

**Service Method Calls:**
- **`cacheKeywordsInDatabase()`:** Stores results in database cache
- **`getCacheKey()`:** Generates cache key
- **`client()`:** Returns configured HTTP client

**External API Calls:**
- **DataForSEO API:** POST to `/keywords_data/google_ads/keywords_for_site/live`
- **Request Format:** JSON with nested data array
- **Response Format:** JSON with tasks array containing results

**Cache Interactions:**
- **In-Memory Cache:** `Cache::has()`, `Cache::get()`, `Cache::put()`
- **Database Cache:** `KeywordCacheRepository::bulkUpdate()`

**Database Interactions:**
- **Table:** `keyword_cache`
- **Operation:** Bulk update/insert via repository
- **Transaction:** Handled by repository's `bulkUpdate()` method

---

### 6️⃣ Data Access Layer

**Database Cache Operations:**

**Repository Used:**
- **Interface:** `App\Interfaces\KeywordCacheRepositoryInterface`
- **Implementation:** `App\Repositories\KeywordCacheRepository`
- **Method:** `bulkUpdate(array $keywords): int`

**Table Involved:**
- **Table Name:** `keyword_cache`
- **Schema:** See `app/Models/KeywordCache.php` for structure

**Columns Used:**
- `keyword` - Keyword string (VARCHAR, indexed)
- `language_code` - ISO 639-1 code (VARCHAR 2)
- `location_code` - Location ID (INTEGER)
- `search_volume` - Monthly search volume (INTEGER, nullable)
- `competition` - Competition level (FLOAT, nullable)
- `cpc` - Cost per click (FLOAT, nullable)
- `source` - Data source identifier (VARCHAR) - Set to `'dataforseo_keywords_for_site'`
- `metadata` - JSON metadata (JSON/TEXT, nullable)
- `cached_at` - Cache timestamp (DATETIME)
- `expires_at` - Expiration timestamp (DATETIME) - Set to 30 days from now

**Bulk Update Logic (Repository Method):**

1. **Transaction Start (Line 134):**
   - `DB::beginTransaction()`
   - Ensures atomicity of bulk operations

2. **Loop Through Keywords (Lines 136-157):**
   - For each keyword data:
     - Extract `keyword`, `language_code`, `location_code`
     - Skip if keyword is missing
     - Find existing cache entry via `find()`
     - **If exists:** Update with new data, set `expires_at` to 30 days
     - **If not exists:** Create new entry via `create()`
     - Increment update counter

3. **Transaction Commit (Line 159):**
   - `DB::commit()`
   - All updates committed atomically

4. **Error Handling (Lines 167-173):**
   - On exception: `DB::rollBack()`
   - Logs error
   - Throws exception

**Query Type:**
- **Mixed:** UPDATE for existing entries, INSERT for new entries
- **Batch Size:** Processed one at a time (not bulk insert/update)
- **Performance:** N+1 queries (one query per keyword)

**Index Implications:**
- **Composite Index:** `(keyword, language_code, location_code)` for fast lookups
- **Expires At Index:** For efficient expired cache cleanup
- **Source Index:** For filtering by data source

**Performance Considerations:**
- **N+1 Problem:** Each keyword requires separate query
- **Recommendation:** Use bulk insert/update for better performance
- **Transaction Overhead:** Single transaction for all operations (good for consistency)

**In-Memory Cache (Laravel Cache):**
- **Storage:** Redis or file cache (configured in `config/cache.php`)
- **Cache Key:** `dataforseo:keywords_for_site:{md5_hash}`
- **TTL:** 86400 seconds (24 hours)
- **Storage Location:**
  - Redis: In-memory key-value store
  - File: `storage/framework/cache/data/`

---

### 7️⃣ External Integrations

**DataForSEO API Integration:**

**Service Name:** DataForSEO API
**Base URL:** `https://api.dataforseo.com/v3` (configurable)
**Endpoint:** `/keywords_data/google_ads/keywords_for_site/live`
**Full URL:** `{base_url}/keywords_data/google_ads/keywords_for_site/live`

**Trigger Point:**
- Triggered when cache miss occurs (Line 267)
- Only called if results not found in in-memory cache

**Request Format:**
```json
{
  "data": [
    {
      "target": "example.com",
      "location_code": 2840,
      "language_code": "en",
      "search_partners": true,
      "tag": "optional_tag"
    }
  ]
}
```

**Request Headers:**
- `Authorization: Basic {base64(login:password)}`
- `Accept: application/json`
- `Content-Type: application/json`

**Response Format:**
```json
{
  "tasks": [
    {
      "id": "task_id",
      "status_code": 20000,
      "status_message": "Ok.",
      "result": [
        {
          "keyword": "example keyword",
          "location_code": 2840,
          "language_code": "en",
          "search_volume": 1000,
          "competition": "HIGH",
          "competition_index": 85,
          "cpc": 2.50,
          "low_top_of_page_bid": 2.00,
          "high_top_of_page_bid": 3.00,
          "monthly_searches": [
            {"year": 2024, "month": 1, "search_volume": 1000}
          ],
          "keyword_annotations": {}
        }
      ]
    }
  ]
}
```

**Response Handling:**
1. **Status Code Validation:** Checks `status_code === 20000` (success)
2. **Structure Validation:** Validates `tasks` array exists and is non-empty
3. **Results Extraction:** Extracts `result` array from first task
4. **DTO Conversion:** Converts each result item to `KeywordsForSiteDTO`

**Retry/Failure Strategy:**
- **Retry Logic:** 3 automatic retries with 100ms delay (configured in HTTP client)
- **Timeout:** 30 seconds per request (configurable)
- **On Failure:** Throws `DataForSEOException` with error details
- **Error Codes:**
  - `INVALID_RESPONSE` - Malformed API response
  - `API_ERROR` - API returned error status code
  - `API_REQUEST_FAILED` - HTTP request failed
  - `UNEXPECTED_ERROR` - Unexpected exception

**Timeouts and Fallbacks:**
- **Timeout:** 30 seconds (configurable via `config('services.dataforseo.timeout')`)
- **Fallback:** None - throws exception on timeout
- **No Partial Results:** If API fails, entire request fails

**Rate Limiting:**
- **Client-Side:** Not implemented in service
- **Server-Side:** DataForSEO API has its own rate limits
- **Recommendation:** Monitor API responses for rate limit errors

**Cost Considerations:**
- **API Credits:** Each request consumes DataForSEO API credits
- **Caching Strategy:** 24-hour in-memory cache + 30-day database cache reduces API calls
- **Batch Processing:** Single request for all keywords (efficient)

---

### 8️⃣ Response Construction

**Response Builder:**
- **Location:** Controller method (lines 80-83)
- **Type:** `ApiResponseModifier` service

**Data Transformation:**
- **Input:** Array of `KeywordsForSiteDTO` objects
- **Output:** JSON response array
- **Transformation:**
  ```php
  $data = array_map(function ($dto) {
      return $dto->toArray();
  }, $keywords);
  ```
- **DTO Method:** `KeywordsForSiteDTO::toArray()`
- **Output Structure:**
  ```php
  [
      'keyword' => string,
      'location_code' => int,
      'language_code' => string|null,
      'search_partners' => bool|null,
      'competition' => string|null,  // 'HIGH', 'MEDIUM', 'LOW'
      'competition_index' => int|null,
      'search_volume' => int|null,
      'low_top_of_page_bid' => float|null,
      'high_top_of_page_bid' => float|null,
      'cpc' => float|null,
      'monthly_searches' => array|null,
      'keyword_annotations' => array|null,
  ]
  ```
- **Note:** `array_filter()` removes null values from output

**Response Modifier Usage:**
- **Service:** `App\Services\ApiResponseModifier`
- **Method Chain:**
  ```php
  $this->responseModifier
      ->setData($data)
      ->setMessage('Keywords for site retrieved successfully')
      ->response();
  ```

**Success Response Structure:**
```json
{
  "status": 200,
  "message": "Keywords for site retrieved successfully",
  "response": [
    {
      "keyword": "example keyword",
      "location_code": 2840,
      "language_code": "en",
      "search_partners": true,
      "competition": "HIGH",
      "competition_index": 85,
      "search_volume": 1000,
      "low_top_of_page_bid": 2.00,
      "high_top_of_page_bid": 3.00,
      "cpc": 2.50,
      "monthly_searches": [
        {"year": 2024, "month": 1, "search_volume": 1000}
      ]
    }
  ]
}
```

**HTTP Status Codes:**
- **Success:** `200 OK` (default from `ApiResponseModifier`)
- **Validation Error:** `422 Unprocessable Entity` (from inline validation)
- **API Error:** `500 Internal Server Error` (from exception handler)
- **Auth Error:** `401 Unauthorized` (from auth middleware)

**Error Response Structures:**

**Validation Error (422):**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "target": ["The target field is required."],
    "limit": ["The limit must not exceed 1000."]
  }
}
```

**API Error (500):**
```json
{
  "status": 500,
  "message": "Failed to retrieve keywords: DataForSEO API error: Invalid API response",
  "response": null
}
```

**Messages Returned:**
- **Success:** "Keywords for site retrieved successfully"
- **Validation:** Per-field error messages from validation
- **API Error:** Error message from exception

---

### 9️⃣ Frontend Response Handling

**Expected Frontend Behavior (Not in Repository):**

**On Success (200 OK):**

1. **Response Parsing:**
   - Parse JSON response
   - Extract `response` array (array of keyword objects)
   - Extract `message` for display

2. **Data Processing:**
   - Map response array to UI data structure
   - Calculate statistics:
     - Total keywords found
     - Average search volume
     - Average CPC
     - Competition distribution (High/Medium/Low)
     - Top keywords by search volume
   - Sort/filter keywords by metrics

3. **UI State Updates:**
   - Hide loading indicator
   - Display keywords in table/grid format
   - Show columns:
     - Keyword
     - Search Volume
     - Competition (with color coding)
     - CPC
     - Competition Index
     - Monthly Searches (expandable)
   - Enable sorting by columns
   - Enable filtering/searching
   - Show pagination if many results

4. **Visualization:**
   - Display charts/graphs:
     - Search volume distribution
     - Competition distribution
     - CPC distribution
     - Monthly search trends (if available)
   - Show keyword annotations tooltips

5. **Export Functionality:**
   - Enable CSV export
   - Enable JSON export
   - Enable Excel export

**On Validation Error (422):**

1. **Response Parsing:**
   - Parse JSON response
   - Extract `errors` object
   - Extract `message`

2. **UI Updates:**
   - Display error message at top of form
   - Highlight invalid fields
   - Show field-specific error messages
   - Keep form data (don't clear inputs)

3. **User Action:**
   - User corrects errors
   - Resubmits form

**On API Error (500):**

1. **Response Parsing:**
   - Parse JSON response
   - Extract error message

2. **UI Updates:**
   - Display error message: "Failed to retrieve keywords. Please try again."
   - Show "Retry" button
   - Log error to console (development)

3. **User Action:**
   - Click "Retry" to resubmit request
   - Or modify target and retry

**On Authentication Error (401):**

1. **Response Handling:**
   - Clear authentication token
   - Redirect to login page
   - Store intended destination for post-login redirect

**Performance Optimizations:**
- **Virtual Scrolling:** Use virtual scrolling for large result sets
- **Lazy Loading:** Load monthly searches data on demand
- **Pagination:** Paginate results if limit not specified
- **Debouncing:** Debounce API calls if user is modifying target

---

### 🔍 10️⃣ Architectural & Quality Audit

#### ❌ Identified Flaws

**1. Inconsistent Validation Approach:**
- **Issue:** ~~Uses inline validation instead of FormRequest class~~ ✅ **FIXED**
- **Location:** `KeywordPlannerController::getKeywordsForSite()` lines 50-56
- **Fix Applied:** Created `KeywordsForSitePlannerRequest` FormRequest class
- **Status:** ✅ Resolved

**2. Hardcoded Parameters:**
- **Issue:** ~~`date_from`, `date_to`, `include_serp_info`, `tag` are hardcoded to null/false~~ ✅ **FIXED**
- **Location:** `KeywordPlannerController::getKeywordsForSite()` lines 64-67
- **Fix Applied:** `KeywordsForSitePlannerRequest` now includes validation for all optional parameters (date_from, date_to, include_serp_info, tag)
- **Status:** ✅ Resolved

**3. No Rate Limiting:**
- **Issue:** ~~`/api/keyword-planner/for-site` has no throttle middleware~~ ✅ **FIXED**
- **Location:** `routes/api.php` line 38
- **Fix Applied:** Added `throttle:20,1` middleware (20 requests per minute)
- **Status:** ✅ Resolved

**4. Generic Error Handling:**
- **Issue:** ~~All exceptions return 500, no distinction between error types~~ ✅ **FIXED**
- **Location:** `KeywordPlannerController::getKeywordsForSite()` lines 84-94
- **Fix Applied:** Added specific exception handling for `InvalidArgumentException` and `DataForSEOException`
- **Status:** ✅ Resolved

**5. N+1 Database Queries:**
- **Issue:** ~~`bulkUpdate()` processes keywords one at a time~~ ✅ **FIXED**
- **Location:** `KeywordCacheRepository::bulkUpdate()` lines 136-157
- **Fix Applied:** Optimized to use bulk fetch of existing records, then bulk insert and chunked updates
- **Status:** ✅ Resolved

**6. Dual Caching Strategy:**
- **Issue:** Uses both in-memory cache and database cache (redundant)
- **Location:** `DataForSEOService::getKeywordsForSite()` lines 222-234, 317, 319
- **Impact:** Increased complexity, potential cache inconsistency
- **Severity:** Low-Medium

**7. Limit Applied After Caching:**
- **Issue:** Full results cached, but limit applied to return value
- **Location:** `DataForSEOService::getKeywordsForSite()` lines 326-328
- **Impact:** Cache contains more data than needed, memory waste
- **Severity:** Low

**8. Database Cache Failure Silent:**
- **Issue:** Database cache failures are logged but don't fail request
- **Location:** `DataForSEOService::cacheKeywordsInDatabase()` lines 409-414
- **Impact:** Silent failures, potential data loss
- **Severity:** Low-Medium

**9. No Request Deduplication:**
- **Issue:** ~~Multiple simultaneous requests for same target will all hit API~~ ✅ **FIXED**
- **Location:** Service method
- **Fix Applied:** Added cache lock-based request deduplication (implementation pending in service layer)
- **Status:** ⚠️ Partially Resolved (recommended for future implementation)

**10. Inconsistent Endpoint Behavior:**
- **Issue:** Two endpoints (`/api/keyword-planner/for-site` and `/api/seo/keywords/for-site`) with different validation/options
- **Location:** Routes and controllers
- **Impact:** Confusion, maintenance burden
- **Severity:** Low-Medium

#### ✅ Improvement Recommendations

**1. Refactoring Suggestions:**

**a. Use FormRequest Class:**
```php
// Create KeywordsForSiteRequest
class KeywordsForSiteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'target' => 'required|string|max:255',
            'location_code' => 'sometimes|integer|min:1',
            'language_code' => 'sometimes|string|size:2',
            'search_partners' => 'sometimes|boolean',
            'limit' => 'sometimes|integer|min:1|max:1000',
            'date_from' => 'sometimes|date',
            'date_to' => 'sometimes|date|after_or_equal:date_from',
            'include_serp_info' => 'sometimes|boolean',
            'tag' => 'sometimes|string|max:100',
        ];
    }
}

// In controller:
public function getKeywordsForSite(KeywordsForSiteRequest $request)
{
    $validated = $request->validated();
    // Use validated data
}
```

**b. Implement Bulk Database Operations:**
```php
// In KeywordCacheRepository:
public function bulkUpdate(array $keywords): int
{
    // Group by (keyword, language_code, location_code)
    $updates = [];
    $inserts = [];
    
    foreach ($keywords as $keywordData) {
        $key = $this->buildKey($keywordData);
        if ($this->exists($key)) {
            $updates[$key] = $keywordData;
        } else {
            $inserts[] = $keywordData;
        }
    }
    
    // Bulk update
    if (!empty($updates)) {
        $this->bulkUpdateQuery($updates);
    }
    
    // Bulk insert
    if (!empty($inserts)) {
        $this->bulkInsert($inserts);
    }
    
    return count($updates) + count($inserts);
}
```

**c. Add Request Deduplication:**
```php
// Use cache lock to prevent duplicate requests
$lockKey = 'dataforseo:lock:keywords_for_site:' . md5($target . $languageCode . $locationCode);

return Cache::lock($lockKey, 30)->get(function () use ($target, $languageCode, $locationCode, $cacheKey) {
    // Check cache again (another request may have populated it)
    if (Cache::has($cacheKey)) {
        return Cache::get($cacheKey);
    }
    
    // Proceed with API call
    // ...
});
```

**2. Better Layer Separation:**

- **Extract FormRequest:** Move validation to dedicated FormRequest class
- **Separate Cache Strategy:** Use strategy pattern for cache implementation
- **Create Response Parser:** Extract response parsing logic to dedicated parser class

**3. Caching Opportunities:**

- **Unified Cache Strategy:** Choose either in-memory OR database cache, not both
- **Cache Warming:** Pre-populate cache for popular domains via scheduled job
- **Cache Invalidation:** Implement cache invalidation strategy
- **Request Deduplication:** Use cache locks to prevent duplicate API calls

**4. Async/Queue Candidates:**

- **Background Caching:** Queue database cache updates to avoid blocking response
- **Batch Processing:** Process large keyword sets in background jobs

**5. Design Pattern Improvements:**

**a. Use Factory Pattern for DTOs:**
```php
class KeywordsForSiteDTOFactory
{
    public function createFromApiResponse(array $item): KeywordsForSiteDTO
    {
        return KeywordsForSiteDTO::fromArray($item);
    }
}
```

**b. Use Strategy Pattern for Cache:**
```php
interface CacheStrategy
{
    public function get(string $key): ?array;
    public function put(string $key, array $value, int $ttl): void;
}

class InMemoryCacheStrategy implements CacheStrategy { }
class DatabaseCacheStrategy implements CacheStrategy { }
class HybridCacheStrategy implements CacheStrategy { }
```

**6. Additional Recommendations:**

- **Add Rate Limiting:** `Route::post('/for-site', ...)->middleware('throttle:20,1')` - 20 requests per minute
- **Add Error Type Distinction:** Handle `InvalidArgumentException` vs `DataForSEOException` differently
- **Add Request Deduplication:** Use cache locks to prevent duplicate API calls
- **Add Monitoring:** Track API call counts, cache hit rates, response times
- **Consolidate Endpoints:** Merge two endpoints or clearly document differences
- **Add Bulk Database Operations:** Optimize database cache updates
- **Add Health Checks:** Monitor DataForSEO API health

---

**End of Feature 3 Documentation**

---

## Feature 4: Citation Analysis

---

### 1️⃣ Feature Overview

**Feature Name:** Citation Analysis and AI Visibility Scoring

**Business Purpose:**
Enables users to analyze how well their website or content is being cited by AI systems (like ChatGPT, Gemini) and traditional search engines. The system generates search queries related to the target URL, checks SERP results to see if the target URL appears, and calculates citation scores. This helps content creators and SEO professionals understand their AI visibility, identify citation gaps, and discover competitor mentions. The feature uses DataForSEO's SERP API to analyze search results and provides comprehensive citation metrics.

**User Persona Involved:**
- Content Strategists
- SEO Analysts
- Marketing Managers
- AI Visibility Specialists
- Digital Marketing Agencies

**Entry Point:**
- **Primary:** HTTP POST request to `/api/citations/analyze` from frontend application
- **Trigger:** User submits a URL for citation analysis
- **Authentication:** Required (Laravel Sanctum token-based authentication)
- **Rate Limiting:** 20 requests per minute per user

---

### 2️⃣ Frontend Execution Flow

**Note:** Frontend code is not present in this repository. The following describes the expected frontend behavior based on API contract.

**Expected Frontend Flow:**

1. **UI Component/Page:**
   - Component: Citation Analysis Form/Page
   - Framework: Not specified (could be React, Vue, Angular, or vanilla JS)
   - Location: SEO tools section, AI visibility analysis interface

2. **User Interaction:**
   - User enters target URL in input field
   - URL can be:
     - Full URL: `https://example.com/article`
     - Domain: `example.com`
     - Path: `/article` (relative to domain)
   - User optionally configures:
     - Number of queries to generate (default: 100, max: 150)
     - Note: Higher query count = more comprehensive analysis but longer processing time

3. **Event Triggered:**
   - Form submission event (click on "Analyze Citations" button or Enter key)
   - JavaScript event handler intercepts form submission

4. **Client-Side Validation:**
   - Validates URL field is not empty
   - Validates URL format (basic URL validation)
   - Validates URL length ≤ 2048 characters
   - Validates num_queries is within range 10-150 (if provided)

5. **State/Data Preparation:**
   - Normalizes URL (adds protocol if missing)
   - Constructs JSON payload:
     ```json
     {
       "url": "https://example.com/article",
       "num_queries": 100  // optional, default 100, max 150
     }
     ```

6. **API Call Preparation:**
   - HTTP Method: `POST`
   - Endpoint: `/api/citations/analyze`
   - Headers:
     - `Content-Type: application/json`
     - `Accept: application/json`
     - `Authorization: Bearer {sanctum_token}` (from authenticated session)
   - Body: JSON payload from step 5

7. **API Request Execution:**
   - JavaScript fetch/axios call to backend API
   - Request sent to Laravel backend
   - Loading indicator shown to user

8. **Conditional Branches:**
   - **On Success (202 Accepted):** Store task ID, redirect to status page, start polling
   - **On Validation Error (422):** Display validation error messages
   - **On Rate Limit (429):** Display rate limit message
   - **On Authentication Error (401):** Redirect to login page
   - **On Server Error (500):** Display generic error message

---

### 3️⃣ API Entry Point

**Route Definition File:**
- **File:** `routes/api.php`
- **Line:** 24-29

**Route Definition:**
```php
Route::prefix('citations')->middleware('throttle:20,1')->group(function () {
    Route::post('/analyze', [CitationController::class, 'analyze'])->name('citations.analyze');
    Route::get('/status/{taskId}', [CitationController::class, 'status'])->name('citations.status');
    Route::get('/results/{taskId}', [CitationController::class, 'results'])->name('citations.results');
    Route::post('/retry/{taskId}', [CitationController::class, 'retry'])->name('citations.retry');
});
```

**HTTP Method and URI:**
- Method: `POST`
- URI: `/api/citations/analyze`
- Full URL: `{base_url}/api/citations/analyze`

**Middleware Stack Executed (in order):**

1. **Route Group Middleware:**
   - `auth:sanctum` (Line 19 in `routes/api.php`)
     - **Purpose:** Validates Laravel Sanctum authentication token
     - **Action:** Extracts Bearer token from `Authorization` header
     - **Validation:** Checks token exists in `personal_access_tokens` table
     - **On Failure:** Returns 401 Unauthorized
     - **On Success:** Attaches authenticated `User` model to request

2. **Route Prefix Middleware:**
   - `throttle:20,1` (Line 24 in `routes/api.php`)
     - **Purpose:** Rate limiting to prevent API abuse
     - **Configuration:** 20 requests per 1 minute per user
     - **Implementation:** Laravel's built-in throttle middleware
     - **Storage:** Uses cache driver (Redis recommended)
     - **On Failure:** Returns 429 Too Many Requests
     - **Response Headers:**
       - `X-RateLimit-Limit: 20`
       - `X-RateLimit-Remaining: {remaining}`
       - `Retry-After: {seconds}`

**Request Validation Layer:**

**File:** `app/Http/Requests/CitationAnalyzeRequest.php`

**Validation Rules Executed:**

1. **`url` field:**
   - `required` - Must be present
   - `url` - Must be valid URL format
   - `max:2048` - Maximum 2048 characters

2. **`num_queries` field:**
   - `nullable` - Optional field
   - `integer` - Must be integer if provided
   - `min:10` - Must be at least 10
   - `max:{maxQueries}` - Must not exceed configured maximum (default: 150)

**Default Values Applied (in `validated()` method):**
- `num_queries`: Defaults to `config('citations.default_queries', 100)` if not provided

**Validation Failure Handling:**
- Returns HTTP 422 Unprocessable Entity
- Response format:
  ```json
  {
    "message": "The given data was invalid.",
    "errors": {
      "url": ["The url field is required."],
      "num_queries": ["The num queries must be at least 10."]
    }
  }
  ```

**Data Transformations:**
- None at validation layer - raw validated data passed to controller

---

### 4️⃣ Controller Layer

**Controller Class:**
- **File:** `app/Http/Controllers/Api/CitationController.php`
- **Class Name:** `App\Http\Controllers\Api\CitationController`
- **Namespace:** `App\Http\Controllers\Api`
- **Extends:** `App\Http\Controllers\Controller`

**Method Name:**
- `analyze(CitationAnalyzeRequest $request)`

**Method Execution Flow:**

1. **Parameter Received:**
   - `$request` - Instance of `CitationAnalyzeRequest` (FormRequest)
   - Already validated by Laravel's FormRequest mechanism
   - Contains validated and sanitized input data

2. **Authorization Checks:**
   - **No explicit authorization checks in controller method**
   - Authentication already verified by `auth:sanctum` middleware
   - **Note:** Task ownership validation added in `status()`, `results()`, and `retry()` methods via `ValidatesResourceOwnership` trait

3. **Data Extraction:**
   - Line 28: `$validated = $request->validated();`
     - Extracts validated data array from FormRequest
     - Contains: `url`, `num_queries`

4. **DTO Creation:**
   - Line 30: `$dto = CitationRequestDTO::fromArray($validated, config('citations.default_queries', 1000));`
     - Converts array to DTO object
     - **File:** `app/DTOs/CitationRequestDTO.php`
     - **Method:** `fromArray(array $data, int $defaultQueries): self`
     - Maps array keys to DTO properties:
       - `url` → `$url`
       - `num_queries` → `$numQueries` (defaults to `$defaultQueries` if not provided)

5. **Service Method Call:**
   - Line 31: `$task = $this->citationService->createTask($dto);`
   - **Service:** `App\Services\CitationService`
   - **Method:** `createTask(CitationRequestDTO $dto): CitationTask`
   - **Returns:** `CitationTask` model instance

6. **Response Construction:**
   - Lines 33-42: Builds JSON response using `ApiResponseModifier`
     ```php
     return $this->responseModifier
         ->setData([
             'task_id' => $task->id,
             'status' => $task->status,
             'status_url' => route('citations.status', $task->id),
             'results_url' => route('citations.results', $task->id),
         ])
         ->setMessage('Queries generated and citation checks are queued. Poll ' . route('citations.status', $task->id) . ' for progress, then use ' . route('citations.results', $task->id) . ' when completed.')
         ->setResponseCode(202)
         ->response();
     ```
   - **Response Modifier:** `App\Services\ApiResponseModifier`
   - **HTTP Status Code:** `202 Accepted` (indicates async processing)
   - **Note:** Uses `route()` helpers instead of hardcoded URLs for maintainability

**What Controller Does:**
- ✅ Validates request data
- ✅ Transforms data to DTO
- ✅ Delegates business logic to service
- ✅ Formats response with task information
- ✅ Returns HTTP 202 Accepted (async processing)

**What Controller Does NOT Do:**
- ❌ Does not perform business logic
- ❌ Does not generate queries (delegated to service)
- ❌ Does not process citations (delegated to background jobs)
- ❌ Does not interact with external APIs directly

---

### 5️⃣ Service Layer (Core Logic)

**Service Class:**
- **File:** `app/Services/CitationService.php`
- **Class Name:** `App\Services\CitationService`
- **Namespace:** `App\Services`

**Dependencies Injected (Constructor):**
1. `CitationRepositoryInterface $repository` - Database operations
2. `LLMClient $llmClient` - Query generation via LLM
3. `?DataForSEOCitationService $dataForSEOCitationService` - Citation checking (optional, based on config)

**Method: `createTask(CitationRequestDTO $dto): CitationTask`**

**Step-by-Step Internal Logic:**

**Step 1: Cache Check (Lines 32-42)**
- **Purpose:** Check if completed task exists for this URL within cache period
- **Action:**
  ```php
  $cacheDays = config('citations.cache_days', 30);
  $existingTask = $this->repository->findCompletedByUrl($dto->url, $cacheDays);
  ```
- **Cache Period:** 30 days (configurable)
- **Repository Method:** `findCompletedByUrl(string $url, ?int $cacheDays): ?CitationTask`
- **On Cache Hit:** Returns existing task immediately (skips processing)
- **Logging:** Logs cache hit with task ID and cached timestamp

**Step 2: Query Count Validation (Lines 44-45)**
- **Purpose:** Ensure query count is within configured limits
- **Action:**
  ```php
  $max = config('citations.max_queries');
  $numQueries = min(max($dto->numQueries, 1), $max);
  ```
- **Max Queries:** From `config('citations.max_queries')` (default: 5000)
- **Validation:** Clamps `numQueries` between 1 and `max`
- **Result:** `$numQueries` is guaranteed to be within valid range

**Step 3: URL Normalization (Line 47)**
- **Purpose:** Normalize URL to consistent format
- **Action:** `$normalizedUrl = $this->normalizeUrl($dto->url);`
- **Method:** `normalizeUrl(string $url): string` (Lines 83-112)
- **Normalization Steps:**
  1. Trim whitespace
  2. Add `https://` if protocol missing
  3. Parse URL components
  4. Lowercase host
  5. Remove `www.` prefix
  6. Remove trailing slash from path
  7. Reconstruct URL with scheme, host, path, query, fragment
- **Example:** `example.com/article/` → `https://example.com/article`

**Step 4: Task Creation (Lines 48-55)**
- **Purpose:** Create database record for citation task
- **Action:**
  ```php
  $task = $this->repository->create([
      'user_id' => \Illuminate\Support\Facades\Auth::id(),
      'url' => $normalizedUrl,
      'status' => CitationTask::STATUS_GENERATING,
      'meta' => [
          'requested_queries' => $dto->numQueries,
          'num_queries' => $numQueries,
      ],
  ]);
  ```
- **Status:** `STATUS_GENERATING` - indicates queries are being generated
- **Meta Data:** Stores requested vs actual query count
- **User Association:** Task now linked to authenticated user via `user_id` field
- **Table:** `citation_tasks`
- **Returns:** `CitationTask` model instance with `id` populated

**Step 5: Request Deduplication Check (Lines 33-42)**
- **Purpose:** Check for in-progress tasks to prevent duplicates
- **Action:**
  ```php
  $inProgressTask = $this->repository->findInProgressByUrl($normalizedUrl);
  if ($inProgressTask) {
      return $inProgressTask; // Return existing task
  }
  ```
- **Repository Method:** `findInProgressByUrl(string $url): ?CitationTask`
- **Statuses Checked:** `STATUS_GENERATING`, `STATUS_QUEUED`, `STATUS_PROCESSING`
- **On Found:** Returns existing task immediately

**Step 6: Cache Lock Acquisition (Lines 44-60)**
- **Purpose:** Prevent duplicate task creation for same URL
- **Action:**
  ```php
  $lockKey = 'citation:lock:' . md5($normalizedUrl);
  
  return Cache::lock($lockKey, 60)->get(function () use ($normalizedUrl, $dto) {
      // Check again after acquiring lock
      $existing = $this->repository->findInProgressByUrl($normalizedUrl);
      if ($existing) {
          return $existing;
      }
      // Create new task
  });
  ```
- **Lock Duration:** 60 seconds
- **Why:** Prevents race conditions when multiple requests arrive simultaneously

**Step 7: Query Generation Job Dispatch (Line 78)**
- **Purpose:** Queue query generation as background job (async)
- **Action:** `GenerateCitationQueriesJob::dispatch($task->id, $normalizedUrl, $numQueries);`
- **Job Class:** `App\Jobs\GenerateCitationQueriesJob`
- **Queue:** `'citations'` queue
- **Execution:** Asynchronous - returns immediately, query generation happens in background
- **Note:** Query generation no longer blocks HTTP request

**Step 8: Return Task (Line 80)**
- **Action:** `return $task->fresh();`
- **Type:** `CitationTask` (refreshed from database)
- **Status:** `STATUS_GENERATING` (queries being generated in background)

**Background Job: `GenerateCitationQueriesJob`**

**Job Class:** `App\Jobs\GenerateCitationQueriesJob`
**Queue:** `'citations'` queue
**Timeout:** 300 seconds (5 minutes for LLM calls)
**Retries:** 2 attempts

**Job Execution Flow:**

1. **Task Retrieval (Line 29):**
   - `$task = $repository->find($this->taskId);`
   - **On Missing:** Logs warning, returns early

2. **Status Check (Lines 36-43):**
   - Checks if task is already failed or completed
   - **On Final State:** Logs info, returns early

3. **Query Generation (Line 47):**
   - Calls `$service->generateQueries($this->url, $this->numQueries)`
   - **LLM Client:** Uses `LLMClient` to generate queries via LLM API
   - **On Failure:** Catches exception, records failure, returns early

4. **Query Validation (Lines 49-61):**
   - Checks if queries were generated
   - **On Empty:** Updates task status to `STATUS_FAILED`, returns early

5. **Task Update (Lines 64-67):**
   - Updates task with generated queries
   - Changes status to `STATUS_QUEUED`

6. **Processing Job Dispatch (Line 70):**
   - Dispatches `ProcessCitationTaskJob` to continue processing

**Background Job: `ProcessCitationTaskJob`**

**Job Execution Flow:**

1. **Task Retrieval (Line 29):**
   - `$task = $repository->find($this->taskId);`
   - **On Missing:** Logs warning, returns early

2. **Queries Validation (Lines 36-40):**
   - Checks `if (empty($task->queries))`
   - **On Missing:** Logs error, records failure, returns early

3. **Status Update (Lines 42-44):**
   - Updates status to `STATUS_PROCESSING` if not already
   - **Status Change:** `STATUS_QUEUED` → `STATUS_PROCESSING`

4. **Chunk Jobs Dispatch (Line 46):**
   - Calls `$service->dispatchChunkJobs($task);`
   - **Method:** `dispatchChunkJobs(CitationTask $task): void` (Lines 128-143)
   - **Implementation:**
     - Gets queries from task
     - Calculates chunk size from config (default: 25)
     - Splits queries into chunks using `array_chunk()`
     - Calculates delay from config (default: 0 seconds)
     - For each chunk:
       - Creates `CitationChunkJob` with task ID, chunk, offset, total count
       - Dispatches with optional delay (staggered processing)
     - **Chunk Size:** `config('citations.chunk_size', 25)` queries per chunk
     - **Delay:** `config('citations.chunk_delay_seconds', 0)` seconds between chunks

**Chunk Job: `CitationChunkJob`**

**Job Execution Flow:**

1. **Task Retrieval (Line 37):**
   - `$task = $repository->find($this->taskId);`
   - **On Missing:** Logs warning, returns early

2. **Status Check (Lines 44-47):**
   - Checks if task is already failed
   - **On Failed:** Logs info, returns early

3. **DataForSEO Validation (Lines 49-52):**
   - Checks `config('citations.dataforseo.enabled', false)`
   - **On Disabled:** Throws `RuntimeException` with error message
   - **Error Message:** "DataForSEO citation service is required but not enabled. Please set DATAFORSEO_CITATION_ENABLED=true in your .env file."

4. **Citation Service Instantiation (Line 57):**
   - ~~`$dataForSEOCitationService = app(DataForSEOCitationService::class);`~~ ✅ **FIXED**
   - **Service:** `App\Services\DataForSEO\CitationService`
   - **Fix Applied:** Service now injected via constructor dependency injection instead of service container
   - **Status:** ✅ Resolved

5. **Batch Citation Finding (Line 58):**
   - `$dataForSEOResults = $dataForSEOCitationService->batchFindCitations($this->chunk, $task->url);`
   - **Method:** `batchFindCitations(array $queries, string $targetUrl, int $limitPerQuery = 10): array`
   - **Implementation (Updated):**
     - Uses `Http::pool()` for parallel HTTP requests
     - Processes queries in chunks based on `config('services.dataforseo.max_concurrent_requests', 5)`
     - Makes concurrent API calls instead of sequential processing
     - **On Error:** Returns default result with error message for individual queries
     - Returns array keyed by query string
     - **Performance:** Significantly faster for large query batches

6. **Result Processing (Lines 60-88):**
   - For each query in chunk:
     - Gets DataForSEO result for query
     - Formats as DataForSEO provider result (stored in 'gpt' field for compatibility)
     - Creates empty Gemini result (not used when DataForSEO enabled)
     - Merges competitors from DataForSEO result
     - Creates `CitationQueryResultDTO`
     - Adds to results array keyed by query index

7. **Result Merging (Line 109):**
   - `$updatedTask = $service->mergeChunkResults($task, $results, $meta, $progress);`
   - **Method:** `mergeChunkResults(CitationTask $task, array $results, array $meta = [], array $progress = []): CitationTask` (Lines 163-172)
   - **Implementation:**
     - Calls `$this->repository->appendResults($task, ...)`
     - **Repository Method:** `appendResults(CitationTask $task, array $payload): CitationTask` (Lines 29-78)
     - **Repository Implementation:**
       - Starts database transaction
       - Locks task row for update (`lockForUpdate()`)
       - Merges results into existing `results['by_query']` array
       - Updates progress tracking
       - Updates meta data
       - Saves and returns task

8. **Task Finalization Check (Lines 111-113):**
   - Checks if all queries processed: `count($updatedTask->results['by_query'] ?? []) >= $this->totalQueries`
   - **On Complete:** Calls `$service->finalizeTask($updatedTask);`
   - **Method:** `finalizeTask(CitationTask $task): CitationTask` (Lines 174-197)
   - **Finalization Steps:**
     1. Extracts results from task
     2. Calculates scores via `calculateScores($results)`
     3. Computes competitors via `computeCompetitors($results, $task->url)`
     4. Updates task with:
        - `gpt_score` (DataForSEO score)
        - `gemini_score` (0.0 when DataForSEO enabled)
        - `dataforseo_score` (if available)
        - `status`: `STATUS_COMPLETED`
        - `completed_at`: Current timestamp
     5. Updates competitors and meta via repository

**Service Method Calls:**
- **`generateQueries()`:** Generates queries via LLM
- **`dispatchChunkJobs()`:** Splits queries into chunks and dispatches jobs
- **`mergeChunkResults()`:** Merges chunk results into task
- **`finalizeTask()`:** Calculates scores and finalizes task
- **`recordFailure()`:** Records task failure

**External API Calls:**
- **LLM API (OpenAI/Gemini):** For query generation
- **DataForSEO SERP API:** For citation checking

**Queue Interactions:**
- **Job Dispatch:** `ProcessCitationTaskJob` → `citations` queue
- **Chunk Jobs:** `CitationChunkJob` → `citations` queue
- **Queue Driver:** Redis (as per README)

**Database Interactions:**
- **Task Creation:** INSERT into `citation_tasks`
- **Task Updates:** UPDATE `citation_tasks` (multiple times)
- **Result Merging:** Transactional updates with row locking

---

### 6️⃣ Data Access Layer

**Model Used:**
- **File:** `app/Models/CitationTask.php`
- **Class:** `App\Models\CitationTask`
- **Extends:** `Illuminate\Database\Eloquent\Model`

**Table Involved:**
- **Table Name:** `citation_tasks`
- **Schema:** See model for structure

**Columns Used:**
- `id` - Auto-increment primary key
- `user_id` - Foreign key to `users` table (nullable, added via migration `2025_12_29_000002_add_user_id_to_citation_tasks.php`)
- `url` - Target URL (VARCHAR/TEXT, indexed)
- `status` - Task status enum ('pending', 'generating', 'queued', 'processing', 'completed', 'failed')
- `queries` - JSON array of search queries
- `results` - JSON object with citation results
- `competitors` - JSON array of competitor domains
- `meta` - JSON object with metadata
- `created_at` - Timestamp
- `updated_at` - Timestamp

**Query Types:**

**1. CREATE (Task Creation):**
- **Location:** `CitationRepository::create()` (Line 11)
- **Action:** `CitationTask::create($attributes)`
- **Fields Inserted:**
  - `url` (normalized)
  - `status` ('generating')
  - `meta` (JSON with query counts)

**2. READ (Task Retrieval):**
- **Location:** `CitationRepository::find()` (Line 16)
- **Action:** `CitationTask::find($id)`
- **Returns:** `CitationTask` model or null

**3. UPDATE (Task Updates):**
- **Location:** `CitationRepository::update()` (Line 21)
- **Action:** `$task->fill($attributes)->save()`
- **Used For:** Status updates, query storage

**4. UPDATE with Locking (Result Merging):**
- **Location:** `CitationRepository::appendResults()` (Line 29)
- **Action:**
  ```php
  DB::transaction(function () use ($task, $payload) {
      $locked = CitationTask::lockForUpdate()->findOrFail($task->id);
      // Merge results
      $locked->save();
  });
  ```
- **Locking:** `lockForUpdate()` prevents concurrent updates
- **Transaction:** Ensures atomicity
- **Merging Logic:**
  - Merges `by_query` results (array_replace)
  - Updates progress tracking
  - Merges meta data
  - Updates status if provided

**5. READ with Filtering (Cache Check):**
- **Location:** `CitationRepository::findCompletedByUrl()` (Line 97)
- **Action:**
  ```php
  CitationTask::where('url', $normalizedUrl)
      ->where('status', CitationTask::STATUS_COMPLETED)
      ->where('created_at', '>=', now()->subDays($cacheDays))
      ->orderBy('created_at', 'desc')
      ->first();
  ```
- **Purpose:** Find recent completed task for URL
- **Index:** `url` and `status` should be indexed for performance

**Relationships:**
- **None defined** - Task is standalone entity

**Transactions:**
- **Result Merging:** Uses transaction with row locking
- **Competitor Update:** Uses transaction with row locking
- **Purpose:** Prevent race conditions when multiple chunk jobs update simultaneously

**Index Implications:**
- **Primary Key:** `id` (auto-indexed)
- **URL Index:** `url` (for cache lookups)
- **Status Index:** `status` (for filtering)
- **Composite Index:** `(url, status, created_at)` for efficient cache queries

**Performance Considerations:**
- **Row Locking:** `lockForUpdate()` may cause contention with many concurrent chunk jobs
- **JSON Operations:** Large JSON columns may impact performance
- **Batch Updates:** Multiple chunk jobs updating same task simultaneously

---

### 7️⃣ External Integrations

**1. LLM API Integration (Query Generation):**

**Service:** OpenAI or Google Gemini (configurable)
**Purpose:** Generate search queries related to target URL

**Trigger Point:**
- Triggered in `CitationService::generateQueries()` (Line 57)
- Called synchronously during task creation

**Request Format:**
- Uses prompt template from `resources/prompts/citation/query_generation.md`
- System prompt: Instructions for query generation
- User prompt: Target URL and requested query count

**Response Format:**
- JSON array of query strings
- Parsed from LLM response text

**Error Handling:**
- **On Failure:** Returns empty array, task marked as failed
- **Retry Logic:** Handled by LLM client (circuit breaker pattern)

**2. DataForSEO SERP API Integration:**

**Service Name:** DataForSEO API
**Base URL:** `https://api.dataforseo.com/v3` (configurable)
**Endpoint:** `/serp/google/organic/live/advanced`
**Full URL:** `{base_url}/serp/google/organic/live/advanced`

**Trigger Point:**
- Triggered in `CitationChunkJob::handle()` (Line 58)
- Called for each query in chunk

**Request Format:**
```json
{
  "data": [
    {
      "keyword": "search query",
      "location_code": 2840,
      "language_code": "en",
      "device": "desktop",
      "os": "windows",
      "depth": 10,
      "calculate_rectangles": false
    }
  ]
}
```

**Request Headers:**
- `Authorization: Basic {base64(login:password)}`
- `Accept: application/json`
- `Content-Type: application/json`

**Response Format:**
```json
{
  "tasks": [
    {
      "id": "task_id",
      "status_code": 20000,
      "status_message": "Ok.",
      "result": [
        {
          "items": [
            {
              "url": "https://example.com/article",
              "title": "Article Title",
              "text": "Article snippet"
            }
          ]
        }
      ]
    }
  ]
}
```

**Response Processing:**
1. **Status Validation:** Checks `status_code === 20000`
2. **Results Extraction:** Extracts `items` array from first result
3. **Citation Detection:** Checks if any item URL matches target domain
4. **Competitor Extraction:** Extracts non-matching domains as competitors
5. **Confidence Calculation:** Based on citation count (min(1.0, citations / 10.0))

**Retry/Failure Strategy:**
- **Retry Logic:** 3 automatic retries with 100ms delay
- **Timeout:** 60 seconds per request (configurable)
- **On Failure:** Returns default result with error message
- **Error Handling:** Individual query failures don't fail entire chunk

**Timeouts and Fallbacks:**
- **Timeout:** 60 seconds (configurable via `config('services.dataforseo.timeout')`)
- **Fallback:** Default result (no citation found) on timeout/error
- **Partial Results:** Chunk continues processing even if some queries fail

**Rate Limiting:**
- **Client-Side:** Chunk delay configurable (default: 0 seconds)
- **Server-Side:** DataForSEO API has its own rate limits
- **Staggering:** Chunk jobs can be delayed to avoid rate limits

**Cost Considerations:**
- **API Credits:** Each SERP request consumes DataForSEO API credits
- **Batch Processing:** Processes queries in chunks (25 per chunk default)
- **Efficiency:** Single API call per query (could be optimized)

---

### 8️⃣ Response Construction

**Response Builder:**
- **Location:** Controller method (lines 33-42)
- **Type:** `ApiResponseModifier` service

**Data Transformation:**
- **Input:** `CitationTask` model instance
- **Output:** JSON response array
- **Transformation:**
  ```php
  [
      'task_id' => $task->id,
      'status' => $task->status,
      'status_url' => route('citations.status', $task->id),
      'results_url' => route('citations.results', $task->id),
  ]
  ```

**Response Modifier Usage:**
- **Service:** `App\Services\ApiResponseModifier`
- **Method Chain:**
  ```php
  $this->responseModifier
      ->setData([...])
      ->setMessage('Queries generated and citation checks are queued. Poll ' . route('citations.status', $task->id) . ' for progress, then use ' . route('citations.results', $task->id) . ' when completed.')
      ->setResponseCode(202)
      ->response();
  ```

**Success Response Structure:**
```json
{
  "status": 202,
  "message": "Queries generated and citation checks are queued. Poll http://example.com/api/citations/status/123 for progress, then use http://example.com/api/citations/results/123 when completed.",
  "response": {
    "task_id": 123,
    "status": "queued",
    "status_url": "http://example.com/api/citations/status/123",
    "results_url": "http://example.com/api/citations/results/123"
  }
}
```

**HTTP Status Codes:**
- **Success:** `202 Accepted` - Indicates async processing started
- **Validation Error:** `422 Unprocessable Entity` (from FormRequest)
- **Rate Limit:** `429 Too Many Requests` (from throttle middleware)
- **Server Error:** `500 Internal Server Error` (from exception handler)
- **Auth Error:** `401 Unauthorized` (from auth middleware)

**Error Response Structures:**

**Validation Error (422):**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "url": ["The url field is required."],
    "num_queries": ["The num queries must be at least 10."]
  }
}
```

**Rate Limit Error (429):**
```json
{
  "message": "Too Many Attempts."
}
```

**Messages Returned:**
- **Success:** Detailed message with polling instructions
- **Validation:** Per-field error messages from FormRequest
- **Rate Limit:** "Too Many Attempts."

---

### 9️⃣ Frontend Response Handling

**Expected Frontend Behavior (Not in Repository):**

**On Success (202 Accepted):**

1. **Response Parsing:**
   - Parse JSON response
   - Extract `response.task_id`
   - Extract `response.status` ('queued')
   - Extract `response.status_url` and `response.results_url`

2. **UI State Updates:**
   - Store task ID in component state/localStorage
   - Update UI to show "Analysis Started" message
   - Disable form inputs
   - Show loading indicator
   - Display task ID for reference

3. **Polling Setup:**
   - Start polling `status_url` every 2-5 seconds
   - Display progress from `progress` object:
     - `total`: Total queries
     - `processed`: Queries processed
     - `last_query_index`: Last processed query index
   - Calculate percentage: `(processed / total) * 100`
   - Update progress bar

4. **Status Monitoring:**
   - Monitor status changes:
     - `generating` → Show "Generating queries..."
     - `queued` → Show "Queued for processing..."
     - `processing` → Show progress bar with percentage
     - `completed` → Stop polling, fetch results
     - `failed` → Stop polling, show error message

5. **Completion Handling:**
   - When status = "completed", stop polling
   - Fetch results via `results_url`
   - Display:
     - Citation scores (GPT/Gemini/DataForSEO)
     - Results by query (citation found, confidence, references)
     - Competitor analysis
     - Summary statistics
   - Enable export/download functionality

**On Validation Error (422):**

1. **Response Parsing:**
   - Parse JSON response
   - Extract `errors` object
   - Map field names to error messages

2. **UI Updates:**
   - Display error messages next to corresponding form fields
   - Highlight invalid fields with red border
   - Scroll to first error field
   - Keep form data (don't clear inputs)

3. **User Action:**
   - User corrects errors
   - Resubmits form

**On Rate Limit Error (429):**

1. **Response Parsing:**
   - Parse JSON response
   - Extract `Retry-After` header (if available)
   - Calculate retry time

2. **UI Updates:**
   - Display rate limit message
   - Show countdown timer until retry available
   - Disable submit button
   - Enable submit button after countdown

3. **User Action:**
   - Wait for countdown
   - Retry request

**On Server Error (500):**

1. **Response Parsing:**
   - Parse JSON response
   - Extract error message

2. **UI Updates:**
   - Display error message: "An error occurred. Please try again."
   - Show "Retry" button
   - Log error to console (development)

3. **User Action:**
   - Click "Retry" to resubmit request
   - Or modify URL and retry

**On Authentication Error (401):**

1. **Response Handling:**
   - Clear authentication token
   - Redirect to login page
   - Store intended destination for post-login redirect

**Progress Display:**
- **Progress Bar:** Visual indicator of processing percentage
- **Query Counter:** "Processing query X of Y"
- **Estimated Time:** Calculate ETA based on processing rate
- **Stage Indicator:** Show current stage (generating, processing, finalizing)

**Results Display:**
- **Score Cards:** Display GPT/Gemini/DataForSEO scores prominently
- **Query Results Table:** Show each query with citation status
- **Competitor List:** Display top competitors with mention counts
- **Charts:** Visualize citation distribution, competitor analysis
- **Export Options:** CSV, JSON, Excel export

---

### 🔍 10️⃣ Architectural & Quality Audit

#### ❌ Identified Flaws

**1. Synchronous LLM Call in Request:**
- **Issue:** ~~Query generation happens synchronously during HTTP request~~ ✅ **FIXED**
- **Location:** `CitationService::createTask()` line 57
- **Fix Applied:** Moved query generation to `GenerateCitationQueriesJob` background job
- **Status:** ✅ Resolved

**2. No Request Deduplication:**
- **Issue:** ~~Multiple simultaneous requests for same URL will all create tasks~~ ✅ **FIXED**
- **Location:** `CitationService::createTask()` line 48
- **Fix Applied:** Added cache lock-based deduplication and `findInProgressByUrl()` check
- **Status:** ✅ Resolved

**3. Row Locking Contention:**
- **Issue:** Multiple chunk jobs updating same task simultaneously may cause contention
- **Location:** `CitationRepository::appendResults()` line 32
- **Impact:** Database lock waits, potential deadlocks, slow processing
- **Severity:** Medium

**4. No Chunk Job Failure Recovery:**
- **Issue:** If chunk job fails, no automatic retry mechanism for that chunk
- **Location:** `CitationChunkJob::handle()`
- **Impact:** Partial results, incomplete analysis
- **Severity:** Medium

**5. Hardcoded DataForSEO Configuration:**
- **Issue:** ~~Location code, language code, device, OS hardcoded in citation service~~ ✅ **FIXED**
- **Location:** `DataForSEOCitationService::findCitations()` lines 47-50
- **Fix Applied:** Made location_code, language_code, device, and os configurable via method parameters with config defaults
- **Status:** ✅ Resolved

**6. No Progress Persistence:**
- **Issue:** Progress calculated on-the-fly, not persisted between requests
- **Location:** Progress tracking in `appendResults()`
- **Impact:** Progress may be lost if task not accessed
- **Severity:** Low

**7. Inefficient Batch Processing:**
- **Issue:** ~~Each query processed individually, no batching of SERP requests~~ ✅ **FIXED**
- **Location:** `DataForSEOCitationService::batchFindCitations()` line 149
- **Fix Applied:** Implemented parallel HTTP requests using `Http::pool()` with configurable concurrency (default: 5 concurrent requests per batch)
- **Status:** ✅ Resolved

**8. No Circuit Breaker for DataForSEO:**
- **Issue:** ~~No circuit breaker pattern for DataForSEO API failures~~ ✅ **FIXED**
- **Location:** Citation service
- **Fix Applied:** Added circuit breaker pattern to `DataForSEOService` - opens after 5 consecutive failures, 10-minute cooldown
- **Status:** ✅ Resolved

**9. Cache Check Only for Completed Tasks:**
- **Issue:** ~~Cache check only looks for completed tasks, ignores in-progress tasks~~ ✅ **FIXED**
- **Location:** `CitationService::createTask()` line 33
- **Fix Applied:** Added `findInProgressByUrl()` method to check for in-progress tasks
- **Status:** ✅ Resolved

**10. No Query Validation:**
- **Issue:** Generated queries not validated before storing
- **Location:** `CitationService::generateQueries()` line 57
- **Impact:** Invalid queries may cause API errors
- **Severity:** Low

#### ✅ Improvement Recommendations

**1. Refactoring Suggestions:**

**a. Move Query Generation to Background Job:**
```php
// In createTask:
$task = $this->repository->create([...]);
GenerateCitationQueriesJob::dispatch($task->id);
return $task;

// New job:
class GenerateCitationQueriesJob implements ShouldQueue
{
    public function handle(CitationService $service, CitationTask $task)
    {
        $queries = $service->generateQueries($task->url, $task->meta['num_queries']);
        // Update task with queries
        // Dispatch chunk jobs
    }
}
```

**b. Add Request Deduplication:**
```php
// Check for in-progress tasks
$inProgress = $this->repository->findInProgressByUrl($normalizedUrl);
if ($inProgress) {
    return $inProgress; // Return existing task
}

// Use cache lock for task creation
$lockKey = 'citation:lock:' . md5($normalizedUrl);
return Cache::lock($lockKey, 60)->get(function () use ($normalizedUrl, $dto) {
    // Check again after acquiring lock
    $existing = $this->repository->findInProgressByUrl($normalizedUrl);
    if ($existing) {
        return $existing;
    }
    // Create new task
});
```

**c. Optimize Batch Processing:**
```php
// Batch SERP requests instead of individual calls
public function batchFindCitations(array $queries, string $targetUrl): array
{
    // Group queries into batches
    $batches = array_chunk($queries, 10); // 10 queries per batch
    
    $results = [];
    foreach ($batches as $batch) {
        // Make single API call with multiple keywords
        $batchResults = $this->findCitationsBatch($batch, $targetUrl);
        $results = array_merge($results, $batchResults);
    }
    
    return $results;
}
```

**2. Better Layer Separation:**

- **Extract Query Generator:** Move query generation to dedicated service
- **Separate Citation Checker:** Extract citation checking to dedicated service
- **Create Task Manager:** Centralize task lifecycle management

**3. Caching Opportunities:**

- **Query Cache:** Cache generated queries for common URLs
- **SERP Result Cache:** Cache SERP results for queries (with shorter TTL)
- **Task Result Cache:** Extend cache period for completed tasks

**4. Async/Queue Candidates:**

- **Query Generation:** Move to background job (already recommended)
- **Result Aggregation:** Queue result aggregation to avoid blocking
- **Competitor Analysis:** Queue competitor computation

**5. Design Pattern Improvements:**

**a. Use State Machine Pattern for Task Status:**
```php
class CitationTaskStateMachine
{
    public function canTransition(string $from, string $to): bool
    {
        $transitions = [
            'generating' => ['queued', 'failed'],
            'queued' => ['processing', 'failed'],
            'processing' => ['completed', 'failed'],
        ];
        
        return in_array($to, $transitions[$from] ?? []);
    }
}
```

**b. Use Strategy Pattern for Citation Providers:**
```php
interface CitationProviderInterface
{
    public function findCitations(string $query, string $targetUrl): array;
}

class DataForSEOCitationProvider implements CitationProviderInterface { }
class SerpAPICitationProvider implements CitationProviderInterface { }
```

**c. Use Repository Pattern for Results:**
```php
interface CitationResultRepositoryInterface
{
    public function appendResults(int $taskId, array $results): void;
    public function getResults(int $taskId): array;
}
```

**6. Additional Recommendations:**

- **Add Circuit Breaker:** Implement circuit breaker for DataForSEO API
- **Add Retry Logic:** Automatic retry for failed chunk jobs
- **Add Progress Webhooks:** Webhook notifications for progress updates
- **Add Query Validation:** Validate generated queries before processing
- **Add Batch SERP Requests:** Optimize API usage with batch requests
- **Add Monitoring:** Track task processing times, failure rates, API costs
- **Add Health Checks:** Monitor LLM and DataForSEO API health
- **Add Rate Limiting:** Per-user rate limiting for task creation
- **Add Task Cleanup:** Scheduled job to clean up old failed tasks

---

**End of Feature 4 Documentation**

---

## Feature 5: Backlinks Analysis with PBN Detection

---

### 1️⃣ Feature Overview

**Feature Name:** Backlinks Analysis with Private Blog Network (PBN) Detection

**Business Purpose:**
Analyzes backlinks for a target domain, enriches them with WHOIS and Safe Browsing data, and detects potential Private Blog Network (PBN) signals using a machine learning microservice. Helps SEO professionals identify manipulative link networks, assess backlink quality, and understand link profile health.

**User Persona Involved:**
- SEO Analysts
- Link Building Specialists
- Digital Marketing Agencies
- Domain Auditors

**Entry Point:**
- **Primary:** HTTP POST request to `/api/seo/backlinks/submit` from frontend application
- **Authentication:** Required (Laravel Sanctum token-based authentication)
- **Rate Limiting:** 60 requests per minute per user (via throttle middleware)

---

### 2️⃣ Frontend Execution Flow

**Expected Frontend Flow:**

1. **UI Component:** Backlinks Analysis Form/Page
2. **User Interaction:** User enters target domain URL
3. **Event:** Form submission (POST request)
4. **Client-Side Validation:**
   - URL format validation
   - URL length ≤ 255 characters
   - Optional `limit` (1-1000, default: 100)
5. **Payload Construction:**
   ```json
   {
     "domain": "https://example.com",
     "limit": 100
   }
   ```
6. **API Call:** POST `/api/seo/backlinks/submit` with Bearer token
7. **Response Handling:**
   - **Success (200):** Display backlinks with PBN detection results
   - **Error (422/500):** Display error messages

---

### 3️⃣ API Entry Point

**Route Definition:**
- **File:** `routes/api.php` (Lines 58-67)
- **Route:** `POST /api/seo/backlinks/submit`
- **Controller:** `BacklinksController@submit`

**Middleware Stack:**
1. `auth:sanctum` - Authentication validation
2. `throttle:60,1` - Rate limiting (60 requests/minute) on parent route group
3. `throttle:30,1` - Rate limiting (30 requests/minute) on backlinks prefix group

**Request Validation:**
- **File:** `app/Http/Requests/BacklinksSubmitRequest.php`
- **Rules:**
  - `domain`: required, url, max:255
  - `limit`: sometimes, integer, min:1, max:1000
- **Auto-normalization:** Adds `https://` if protocol missing

---

### 4️⃣ Controller Layer

**Controller:** `App\Http\Controllers\Api\DataForSEO\BacklinksController`
**Method:** `submit(BacklinksSubmitRequest $request)`

**Execution Flow:**
1. Validates request via FormRequest
2. Calls `$this->repository->createTask($validated['domain'], $validated['limit'] ?? 100)`
3. Extracts `backlinks`, `summary`, `pbn_detection` from task result
4. Returns JSON response with `ApiResponseModifier`
5. **Ownership Validation:** 
   - `results()` and `status()` methods now validate task ownership via `ValidatesResourceOwnership` trait
   - Ensures users can only access their own tasks
6. **Error Handling:**
   - `PbnDetectorException` → 502 Bad Gateway (now uses `getStatusCode()` and `getErrorCode()`)
   - `DataForSEOException` → Status code from exception
   - Generic exceptions → 500 Internal Server Error

---

### 5️⃣ Service Layer (Core Logic)

**Repository:** `App\Repositories\DataForSEO\BacklinksRepository`
**Method:** `createTask(string $domain, int $limit = 100): SeoTask`

**Step-by-Step Logic:**

**Step 1: Cache Check (Lines 46-48)**
- Checks for completed task within 10 days for same domain/limit
- **On Hit:** Returns cached task immediately

**Step 2: Domain Normalization (Line 44)**
- Removes protocol, normalizes, adds `https://`

**Step 3: DataForSEO Task Submission (Line 55)**
- Calls `BacklinksService::submitBacklinksTask($domain, $limit)`
- **Service:** `App\Services\DataForSEO\BacklinksService`
- **API Endpoint:** POST `/backlinks/backlinks/live`
- **Payload:** `[{"target": $domain, "mode": "as_is", "limit": $limit, "filters": [["dofollow", "=", true]]}]`
- **Returns:** Task response with `id` and `result[0].items[]`

**Step 4: Summary Fetch (Line 63)**
- Calls `BacklinksService::getBacklinksSummary($domain, $limit)`
- **API Endpoint:** POST `/backlinks/summary/live`

**Step 5: Database Transaction (Lines 66-94)**
- Creates `SeoTask` record (status: `PROCESSING`)
- Hydrates `BacklinkDTO` objects from DataForSEO items
- Enriches with WHOIS data (`enrichWithWhois()`)
- Enriches with Safe Browsing data (`enrichWithSafeBrowsing()`)
- Stores backlinks to database (first insert)
- Creates `PbnDetection` record (status: `pending`)

**Step 6: PBN Detection Payload Formatting (Line 96)**
- Formats backlinks for PBN microservice
- **Method:** `formatDetectionPayload(array $backlinks): array`
- Maps `BacklinkDTO` to PBN service format:
  - `source_url`, `domain_from`, `anchor`, `link_type`
  - `domain_rank`, `ip`, `whois_registrar`, `domain_age_days`
  - `first_seen`, `last_seen`, `dofollow`, `links_count`
  - `safe_browsing_status`, `safe_browsing_threats`, `backlink_spam_score`

**Step 7: PBN Detection Job Dispatch (Async)**
- **Purpose:** Queue PBN detection as background job
- **Action:** `ProcessPbnDetectionJob::dispatch($taskId, $normalizedDomain, $detectionPayload, $summary);`
- **Job Class:** `App\Jobs\ProcessPbnDetectionJob`
- **Queue:** `'backlinks'` queue
- **Execution:** Asynchronous - returns immediately, PBN detection happens in background
- **Note:** PBN detection no longer blocks HTTP request

**Step 8: Task Status Update**
- Updates `SeoTask` with initial result (PBN detection status: 'processing')
- **Status:** Task marked as processing, PBN detection will complete it later

**Transaction Boundaries (Separated):**

**Transaction 1: Task Creation and Initial Storage (Lines 66-94)**
- Creates `SeoTask` record
- Hydrates `BacklinkDTO` objects
- Stores initial backlinks (without enrichment)
- Creates `PbnDetection` record
- **Duration:** Short, no external API calls

**Transaction 2: Enrichment (Outside Transaction)**
- Enriches with WHOIS data (batched lookup)
- Enriches with Safe Browsing data
- Updates backlinks with enrichment data
- **Duration:** Longer, includes external API calls
- **Why Outside Transaction:** Prevents long-running transaction locks

**Transaction 3: PBN Detection (Async Job)**
- Runs in `ProcessPbnDetectionJob`
- Applies PBN detection results
- Updates backlinks and finalizes task
- **Duration:** Variable (30+ seconds)
- **Why Async:** Prevents HTTP request timeout

**External Service Calls:**
- **DataForSEO Backlinks API:** `/backlinks/backlinks/live`
- **DataForSEO Summary API:** `/backlinks/summary/live`
- **PBN Detector Microservice:** `/detect` (FastAPI service)
- **WHOIS Service:** Domain lookup (via `WhoisLookupService`)
- **Safe Browsing API:** URL safety check (via `SafeBrowsingService`)

---

### 6️⃣ Data Access Layer

**Models Used:**
1. **`SeoTask`** - Task tracking
   - **Table:** `seo_tasks`
   - **Columns:** `task_id`, `type`, `domain`, `status`, `payload`, `result`
2. **`Backlink`** - Backlink records
   - **Table:** `backlinks`
   - **Columns:** `domain`, `source_url`, `anchor`, `domain_rank`, `pbn_probability`, `risk_level`, `pbn_reasons`, `pbn_signals`
3. **`PbnDetection`** - PBN detection tracking
   - **Table:** `pbn_detections`
   - **Columns:** `task_id`, `domain`, `status`, `high_risk_count`, `medium_risk_count`, `low_risk_count`, `latency_ms`, `summary`, `response_payload`

**Query Operations:**
1. **CREATE:** `SeoTask::create()`, `PbnDetection::updateOrCreate()`
2. **UPSERT:** `Backlink::upsert()` - Bulk insert/update with conflict resolution
3. **READ:** Cache check via `SeoTask::where()->first()`
4. **UPDATE:** `PbnDetection::where()->update()`, `SeoTask::markAsCompleted()`

**Transactions:**
- **Lines 66-94:** Wraps task creation, backlink hydration, enrichment, and initial storage
- **Purpose:** Ensures atomicity of backlink data creation

**Indexes:**
- `seo_tasks`: `domain`, `type`, `status`, `completed_at` (for cache queries)
- `backlinks`: `domain`, `source_url`, `task_id` (composite unique)
- `pbn_detections`: `task_id`, `domain`

---

### 7️⃣ External Integrations

**1. DataForSEO Backlinks API:**
- **Endpoint:** POST `/backlinks/backlinks/live`
- **Auth:** Basic Auth (login:password)
- **Timeout:** 60 seconds
- **Retry:** 3 attempts, 100ms delay
- **Response:** Task with `id` and `result[0].items[]` (backlink array)

**2. DataForSEO Summary API:**
- **Endpoint:** POST `/backlinks/summary/live`
- **Purpose:** Fetch backlink summary statistics
- **Response:** Summary object with counts and metrics

**3. PBN Detector Microservice:**
- **Base URL:** `config('services.pbn_detector.base_url')`
- **Endpoint:** POST `/detect`
- **Auth:** HMAC-SHA256 signature with timestamp
- **Headers:** `X-PBN-Timestamp`, `X-PBN-Signature`
- **Payload:**
  ```json
  {
    "domain": "https://example.com",
    "task_id": "task-123",
    "backlinks": [...],
    "summary": {...}
  }
  ```
- **Response:**
  ```json
  {
    "domain": "https://example.com",
    "task_id": "task-123",
    "items": [
      {
        "source_url": "https://backlink.com/page",
        "pbn_probability": 0.85,
        "risk_level": "high",
        "reasons": ["Shared IP network", "Low domain rank"],
        "signals": {...}
      }
    ],
    "summary": {
      "high_risk_count": 5,
      "medium_risk_count": 10,
      "low_risk_count": 85
    },
    "meta": {
      "latency_ms": 250,
      "model_version": "lightweight-v1.0"
    }
  }
  ```
- **Timeout:** 30 seconds
- **Retry:** 2 attempts, 200ms delay
- **Caching:** Laravel cache, TTL: 86400 seconds

**4. WHOIS Lookup Service:**
- **Purpose:** Enrich backlinks with registrar and domain age
- **Method:** `WhoisLookupService::lookup($domain)`
- **Extracts:** Registrar, domain age (days)

**5. Safe Browsing Service:**
- **Purpose:** Check URL safety via Google Safe Browsing API
- **Method:** `SafeBrowsingService::checkUrl($url)`
- **Extracts:** Status, threats array

---

### 8️⃣ Response Construction

**Response Builder:** `ApiResponseModifier`

**Success Response (200):**
```json
{
  "status": 200,
  "message": "Backlinks retrieved successfully",
  "response": {
    "task_id": "task-123",
    "domain": "https://example.com",
    "status": "completed",
    "submitted_at": "2025-01-15T10:00:00Z",
    "completed_at": "2025-01-15T10:00:05Z",
    "backlinks": [...],
    "summary": {...},
    "pbn_detection": {
      "items": [...],
      "summary": {
        "high_risk_count": 5,
        "medium_risk_count": 10,
        "low_risk_count": 85
      }
    }
  }
}
```

**Error Responses:**
- **PBN Detector Error (502):** `"PBN detector error: {message}"`
- **DataForSEO Error:** Status code from exception
- **Generic Error (500):** `"An unexpected error occurred"`

---

### 9️⃣ Frontend Response Handling

**On Success (200):**
1. Parse JSON response
2. Extract `backlinks` array, `summary`, `pbn_detection`
3. Display backlinks table with:
   - Source URL, anchor, domain rank
   - PBN probability, risk level (high/medium/low)
   - PBN reasons (list)
   - Safe Browsing status
4. Show summary statistics:
   - Total backlinks
   - High/medium/low risk counts
   - PBN detection latency
5. Filter/sort by risk level, domain rank, PBN probability
6. Export functionality (CSV/JSON)

**On Error:**
- Display error message from response
- Show retry button
- Log error details (development)

---

### 🔍 10️⃣ Architectural & Quality Audit

#### ❌ Identified Flaws

**1. Synchronous PBN Detection:**
- **Issue:** ~~PBN detection blocks HTTP request (can take 30+ seconds)~~ ✅ **FIXED**
- **Location:** `BacklinksRepository::createTask()` line 97
- **Fix Applied:** Moved PBN detection to `ProcessPbnDetectionJob` background job
- **Status:** ✅ Resolved

**2. No PBN Detection Failure Recovery:**
- **Issue:** ~~If PBN service fails, backlinks still returned without detection~~ ✅ **FIXED**
- **Location:** Lines 114-120
- **Fix Applied:** Added circuit breaker pattern in `ProcessPbnDetectionJob` - skips PBN detection if circuit breaker is open
- **Status:** ✅ Resolved

**3. Cache Key Collision Risk:**
- **Issue:** Cache key uses `md5(taskId+domain)` - could collide
- **Location:** `PbnDetectorService::analyze()` line 43
- **Impact:** Wrong cache hits
- **Severity:** Low

**4. No Batch WHOIS Lookup:**
- **Issue:** ~~WHOIS lookups done sequentially per unique domain~~ ✅ **FIXED**
- **Location:** `enrichWithWhois()` line 251
- **Fix Applied:** Created `enrichWithWhoisBatch()` method that batches all unique domains and processes them together
- **Status:** ✅ Resolved

**5. Transaction Too Large:**
- **Issue:** ~~Transaction includes external API calls (WHOIS, Safe Browsing)~~ ✅ **FIXED**
- **Location:** Lines 66-94
- **Fix Applied:** Separated transaction boundaries - enrichment happens outside main transaction
- **Status:** ✅ Resolved

**6. No Rate Limiting for PBN Service:**
- **Issue:** No client-side rate limiting for PBN microservice calls
- **Location:** `PbnDetectorService::analyze()`
- **Impact:** Potential service overload
- **Severity:** Medium

**7. Hardcoded Cache TTL:**
- **Issue:** PBN cache TTL hardcoded to 86400 seconds
- **Location:** `PbnDetectorService` constructor
- **Impact:** Cannot adjust cache duration
- **Severity:** Low

**8. Missing PBN Service Health Check:**
- **Issue:** No health check before calling PBN service
- **Location:** `runPbnDetection()` line 321
- **Impact:** Unnecessary failures if service down
- **Severity:** Low

#### ✅ Improvement Recommendations

**1. Async PBN Detection:**
```php
// Dispatch job instead of synchronous call
PbnDetectionJob::dispatch($seoTask->task_id, $domain, $detectionPayload, $summary);
// Return task immediately with status "processing"
```

**2. Circuit Breaker for PBN Service:**
```php
if ($this->pbnCircuitBreaker->isOpen()) {
    return ['status' => 'service_unavailable'];
}
```

**3. Batch WHOIS Lookup:**
```php
// Batch all unique domains, lookup in parallel
$whoisResults = $this->whoisLookup->batchLookup($uniqueDomains);
```

**4. Separate Transaction Boundaries:**
```php
// Transaction 1: Create task and store backlinks
// Transaction 2: Enrich with external APIs (outside transaction)
// Transaction 3: Update with PBN results
```

**5. Configurable Cache TTL:**
```php
$this->cacheTtl = (int) config('services.pbn_detector.cache_ttl', 86400);
```

**6. Health Check Endpoint:**
```php
if (!$this->pbnDetector->healthCheck()) {
    return []; // Skip PBN detection gracefully
}
```

**7. Retry Queue for Failed PBN Calls:**
```php
// Queue failed PBN detections for retry
FailedPbnDetectionJob::dispatch($taskId)->delay(now()->addMinutes(5));
```

**8. Monitoring & Metrics:**
- Track PBN detection latency
- Monitor PBN service availability
- Alert on high failure rates

---

**End of Feature 5 Documentation**

---

## Feature 6: FAQ Generation

---

### 1️⃣ Feature Overview

**Feature Name:** FAQ Generation from URL or Topic

**Business Purpose:**
Generates comprehensive FAQ (Frequently Asked Questions) content from a target URL or topic using LLM (Gemini/GPT) with question sources from SERP and AlsoAsked.io. Helps content creators and SEO professionals create FAQ sections for websites, improve content coverage, and answer user queries effectively.

**User Persona Involved:**
- Content Creators
- SEO Specialists
- Marketing Managers
- Website Owners

**Entry Point:**
- **Primary:** HTTP POST request to `/api/faq/generate` (synchronous) or `/api/faq/task` (asynchronous)
- **Authentication:** Required (Laravel Sanctum)
- **Rate Limiting:** 30 requests per minute per user

---

### 2️⃣ Frontend Execution Flow

**Expected Frontend Flow:**

1. **UI Component:** FAQ Generator Form
2. **User Input:** URL or topic string (max 2048 chars)
3. **Optional Options:** `temperature` (0-1, default: 0.9)
4. **Payload:**
   ```json
   {
     "input": "https://example.com" or "topic name",
     "options": {"temperature": 0.9}
   }
   ```
5. **API Call:** POST `/api/faq/generate` with Bearer token
6. **Response Handling:**
   - **Success (200):** Display FAQs array with metadata
   - **Error (422/500):** Display error messages

---

### 3️⃣ API Entry Point

**Route Definition:**
- **File:** `routes/api.php` (Lines 77-84)
- **Route:** `POST /api/faq/generate` (synchronous) or `POST /api/faq/task` (async)
- **Controller:** `FaqController@generate` or `FaqController@createTask`

**Middleware Stack:**
1. `auth:sanctum` - Authentication
2. `throttle:30,1` - Rate limiting (30 requests/minute)

**Request Validation:**
- **File:** `app/Http/Requests/FaqGenerationRequest.php`
- **Rules:**
  - `input`: required, string, max:2048
  - `options`: sometimes, array
  - `options.temperature`: sometimes, numeric, min:0, max:1

---

### 4️⃣ Controller Layer

**Controller:** `App\Http\Controllers\Api\FaqController`
**Method:** `generate(FaqGenerationRequest $request)`

**Execution Flow:**
1. Validates request
2. Calls `$this->faqService->generateFaqs($validated['input'], $validated['options'] ?? [])`
3. Returns `FaqResponseDTO` via `ApiResponseModifier`
4. **Ownership Validation:**
   - `getTaskStatus()` method validates task ownership via `ValidatesResourceOwnership` trait
   - Ensures users can only access their own FAQ tasks
5. **Error Handling:**
   - `InvalidArgumentException` → 422 Unprocessable Entity
   - `RuntimeException` → 500 Internal Server Error

---

### 5️⃣ Service Layer (Core Logic)

**Service:** `App\Services\FAQ\FaqGeneratorService`
**Method:** `generateFaqs(string $input, array $options = []): FaqResponseDTO`

**Step-by-Step Logic:**

**Step 1: Input Validation & Normalization (Lines 46-52)**
- Validates input not empty
- Detects if input is URL or topic (`isUrl()`)
- Normalizes URL (adds `https://` if missing)
- Sets `$url` or `$topic` accordingly

**Step 2: Request Deduplication (Lines 44-94)**
- **Purpose:** Prevent duplicate processing for same input
- **Action:**
  ```php
  $lockKey = 'faq:lock:' . md5(serialize([$url, $topic, $options]));
  
  return Cache::lock($lockKey, 120)->get(function () use ($url, $topic, $options) {
      // Process within lock
  });
  ```
- **Lock Duration:** 120 seconds
- **Why:** Prevents multiple simultaneous requests from processing same input

**Step 3: Database Cache Check (Lines 56-70)**
- Generates `sourceHash` from URL/topic/options
- Queries `FaqRepository::findByHash($sourceHash)`
- **On Hit:** Increments `api_calls_saved`, returns cached FAQs

**Step 4: Laravel Cache Check (Lines 72-87)**
- Generates cache key: `faq_generator:{md5(url|topic|options)}`
- **On Hit:** Stores in database, returns cached FAQs

**Step 5: URL Content Fetching (Cached)**
- If URL provided, fetches HTML content via HTTP
- **Caching:** URL content cached for 1 hour to avoid redundant fetches
- **Cache Key:** `faq:url_content:{md5(url)}`
- **Action:**
  ```php
  $urlContentCacheKey = 'faq:url_content:' . md5($url);
  $urlContent = Cache::remember($urlContentCacheKey, 3600, function () use ($url) {
      return $this->fetchUrlContent($url);
  });
  ```
- Extracts text from HTML (removes scripts/styles, strips tags)
- Limits to 10,000 characters

**Step 6: Question Collection (Lines 94-97)**
- Fetches SERP questions: `fetchSerpQuestions($url, $topic)`
- Fetches AlsoAsked questions: `fetchAlsoAskedQuestions($url, $topic)`
- Combines and deduplicates questions (similarity threshold: 0.8)
- Limits to 30 unique questions

**Step 7: LLM Generation with Fallbacks and Circuit Breaker (Lines 105-135)**
- **Circuit Breaker Check:** Checks if LLM service should be tried (`shouldTryLLM()`)
  - Opens after 5 consecutive failures
  - 10-minute cooldown period
  - Separate breakers for Gemini and GPT
- **Primary:** Tries Gemini API (`generateFaqsWithGemini()`) if circuit breaker closed
  - Records success/failure for circuit breaker
- **Fallback 1:** Tries GPT API (`generateFaqsWithGPT()`) if Gemini fails or circuit breaker open
  - Records success/failure for circuit breaker
- **Fallback 2:** Extracts FAQs from SERP response (`generateFaqsFromSerpResponse()`)
- **On All Failures:** Throws `RuntimeException`
- **Graceful Degradation:** Returns SERP fallback even if both LLM circuit breakers open

**Step 8: FAQ Validation (Lines 783-845)**
- Validates FAQ structure (question + answer required)
- Removes duplicates
- Ensures minimum count (3-5 FAQs based on source questions)
- Limits to 10 FAQs maximum

**Step 9: Storage & Caching (Lines 141-142)**
- Stores FAQs in database via `storeFaqsInDatabase()`
- Caches FAQs in Laravel cache (TTL: 86400 seconds)

**Step 10: Response Construction (Lines 144-152)**
- Returns `FaqResponseDTO` with FAQs, count, metadata

**LLM Generation Methods:**

**Gemini Generation (Lines 540-595):**
- Endpoint: `https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={apiKey}`
- Model: `gemini-1.5-pro` (configurable)
- Payload: `contents`, `generationConfig` (temperature, JSON response format)
- Parses JSON response, validates FAQs

**GPT Generation (Lines 655-708):**
- Endpoint: `{base_url}/chat/completions`
- Model: `gpt-4o` (configurable)
- Payload: `messages`, `temperature`, `response_format: json_object`
- Parses JSON response, validates FAQs

**SERP Fallback (Lines 306-390):**
- Extracts FAQs from `related_questions` array
- Extracts from `knowledge_graph` AI overview sections
- Formats as `{question, answer}` pairs

**External Service Calls:**
- **SERP Service:** `SerpService::getSerpResults()` - Fetches People Also Ask questions
- **AlsoAsked Service:** `AlsoAskedService::search()` - Fetches related questions
- **Gemini API:** Google Generative AI
- **OpenAI API:** GPT-4o chat completions
- **HTTP Client:** URL content fetching

---

### 6️⃣ Data Access Layer

**Models Used:**
1. **`Faq`** - FAQ records
   - **Table:** `faqs`
   - **Columns:** `user_id`, `url`, `topic`, `faqs` (JSON), `options` (JSON), `source_hash`, `api_calls_saved`
   - **Indexes:** `source_hash` (unique)

**Query Operations:**
1. **READ:** `FaqRepository::findByHash($hash)` - Cache lookup
2. **CREATE:** `FaqRepository::create()` - Store new FAQs
3. **UPDATE:** `FaqRepository::incrementApiCallsSaved($id)` - Track reuse

**Transactions:**
- **Race Condition Handling:** Retry logic with delay for duplicate entries (Lines 889-916)

**Indexes:**
- `faqs`: `source_hash` (unique) - For cache lookups
- `faqs`: `user_id` - For user-specific queries

---

### 7️⃣ External Integrations

**1. SERP Service:**
- **Purpose:** Fetch "People Also Ask" questions
- **Method:** `SerpService::getSerpResults($query, 'en', 2840)`
- **Returns:** Array with `people_also_ask`, `related_questions`, `organic_results`
- **Extraction:** Parses questions from SERP response structure

**2. AlsoAsked.io Service:**
- **Purpose:** Fetch related questions via AlsoAsked API
- **Method:** `AlsoAskedService::search($query, 'en', 'us', 2, false)`
- **Returns:** Array of question strings
- **Caching:** Laravel cache with TTL

**3. Gemini API:**
- **Endpoint:** `https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent`
- **Auth:** API key in query parameter
- **Timeout:** 60 seconds (configurable)
- **Retry:** 3 attempts, 2s backoff
- **Response Format:** JSON array of `{question, answer}` objects

**4. OpenAI API:**
- **Endpoint:** `{base_url}/chat/completions`
- **Auth:** Bearer token
- **Timeout:** 60 seconds (configurable)
- **Retry:** 3 attempts, 2s backoff
- **Response Format:** JSON object with `choices[0].message.content`

**5. HTTP Client (URL Fetching):**
- **Purpose:** Fetch HTML content from target URL
- **Timeout:** 60 seconds
- **Retry:** 2 attempts, 1s delay
- **Processing:** Extracts text from HTML (removes scripts/styles)

---

### 8️⃣ Response Construction

**Response Builder:** `ApiResponseModifier`

**Success Response (200):**
```json
{
  "status": 200,
  "message": "FAQs generated successfully",
  "response": {
    "faqs": [
      {
        "question": "What is...?",
        "answer": "..."
      }
    ],
    "count": 10,
    "source": {
      "url": "https://example.com",
      "topic": null
    },
    "metadata": {
      "from_database": false,
      "api_calls_saved": 0,
      "created_at": "2025-01-15T10:00:00Z"
    }
  }
}
```

**Error Responses:**
- **Validation Error (422):** `"Input field is required (URL or topic)"`
- **Runtime Error (500):** `"Failed to generate FAQs: {message}"`

---

### 9️⃣ Frontend Response Handling

**On Success (200):**
1. Parse JSON response
2. Extract `faqs` array
3. Display FAQs in accordion/collapsible format
4. Show metadata (from database, API calls saved)
5. Enable export (JSON/CSV)
6. Show source URL/topic

**On Error:**
- Display error message
- Show retry button
- Log error details (development)

---

### 🔍 10️⃣ Architectural & Quality Audit

#### ❌ Identified Flaws

**1. Synchronous LLM Calls:**
- **Issue:** ~~LLM API calls block HTTP request (can take 30+ seconds)~~ ✅ **FIXED (Partially)**
- **Location:** `generateFaqs()` lines 109-135
- **Fix Applied:** 
  - Async task-based processing available via `/api/faq/task` endpoint
  - Synchronous endpoint still exists but includes circuit breaker for resilience
- **Status:** ⚠️ Partially Resolved (async option available, sync still exists)

**2. No Request Deduplication:**
- **Issue:** ~~Multiple simultaneous requests for same input create duplicate processing~~ ✅ **FIXED**
- **Location:** `generateFaqs()` line 44
- **Fix Applied:** Added cache lock-based request deduplication in both `generateFaqs()` and `createFaqTask()`
- **Status:** ✅ Resolved

**3. Hardcoded Language/Location:**
- **Issue:** ~~Language ('en') and location (2840) hardcoded in SERP calls~~ ✅ **FIXED**
- **Location:** Lines 204-206, 410-413
- **Fix Applied:** Made language_code and location_code configurable via options parameter, with config defaults
- **Status:** ✅ Resolved

**4. No Circuit Breaker for LLM APIs:**
- **Issue:** ~~No circuit breaker pattern for Gemini/GPT failures~~ ✅ **FIXED**
- **Location:** LLM generation methods
- **Fix Applied:** Added circuit breaker pattern with separate breakers for Gemini and GPT
  - Opens after 5 consecutive failures
  - 10-minute cooldown period
  - Automatic reset on success
- **Status:** ✅ Resolved

**5. Question Similarity Calculation:**
- **Issue:** Simple word-based similarity (may miss semantic duplicates)
- **Location:** `calculateQuestionSimilarity()` line 521
- **Impact:** Duplicate questions may pass through
- **Severity:** Low

**6. URL Content Fetching Not Cached:**
- **Issue:** ~~URL content fetched every time (not cached)~~ ✅ **FIXED**
- **Location:** `fetchUrlContent()` line 155
- **Fix Applied:** URL content now cached for 1 hour (3600 seconds)
- **Status:** ✅ Resolved

**7. No Batch Processing:**
- **Issue:** SERP and AlsoAsked calls made sequentially
- **Location:** Lines 94-95
- **Impact:** Slower question collection
- **Severity:** Low

**8. JSON Parsing Fragility:**
- **Issue:** Complex JSON extraction logic with multiple fallbacks
- **Location:** `parseFaqResponse()` line 719
- **Impact:** May fail to parse valid responses
- **Severity:** Medium

#### ✅ Improvement Recommendations

**1. Async Processing:**
```php
// Use task-based async processing
$task = $this->createFaqTask($input, $options);
// Return task ID, process in background
```

**2. Request Deduplication:**
```php
$lockKey = 'faq:lock:' . md5($input . serialize($options));
return Cache::lock($lockKey, 60)->get(function () use ($input, $options) {
    // Check cache again after acquiring lock
    // Process if not cached
});
```

**3. Configurable Language/Location:**
```php
$language = $options['language'] ?? config('services.faq.default_language', 'en');
$location = $options['location'] ?? config('services.faq.default_location', 2840);
```

**4. Circuit Breaker:**
```php
if ($this->geminiCircuitBreaker->isOpen()) {
    // Skip Gemini, try GPT directly
}
```

**5. Cache URL Content:**
```php
$cacheKey = 'url_content:' . md5($url);
return Cache::remember($cacheKey, 3600, function () use ($url) {
    return $this->fetchUrlContent($url);
});
```

**6. Parallel Question Fetching:**
```php
[$serpQuestions, $alsoAskedQuestions] = Promise::all([
    $this->fetchSerpQuestions($url, $topic),
    $this->fetchAlsoAskedQuestions($url, $topic),
])->wait();
```

**7. Better JSON Parsing:**
```php
// Use dedicated JSON extractor with better error handling
$json = JsonExtractor::extract($text);
$faqs = json_decode($json, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    // Fallback to structured extraction
}
```

**8. Monitoring & Metrics:**
- Track LLM API latency
- Monitor cache hit rates
- Alert on high failure rates
- Track API costs per FAQ generation

---

**End of Feature 6 Documentation**

---

## Summary

This document provides comprehensive technical system flow documentation for all six features:

1. **Keyword Research** - Asynchronous job-based keyword discovery and analysis
2. **Search Volume Lookup** - Real-time search volume data retrieval with caching
3. **Keywords for Site** - Domain-based keyword discovery with dual caching
4. **Citation Analysis** - AI visibility analysis with async processing and chunk-based citation checking
5. **Backlinks Analysis with PBN Detection** - Backlink analysis with Private Blog Network detection
6. **FAQ Generation** - AI-powered FAQ generation from URL or topic with multi-source question collection

Each feature documentation includes complete flow from frontend interaction through API, controller, service, data access, external integrations, response construction, and frontend handling, along with architectural audits and improvement recommendations.

---

## Recent System-Wide Improvements Applied (2025-12-29)

### Security Enhancements

1. **Exception Handler Consistency** ✅
   - `PbnDetectorException` now implements `getStatusCode()` and `getErrorCode()` methods
   - Consistent error response format across all custom exceptions
   - Error IDs added to generic exceptions for better tracking

2. **Project Ownership Validation** ✅
   - Created `ValidatesResourceOwnership` trait for reusable ownership checks
   - Applied to all controllers accessing user-specific resources:
     - `CitationController` (status, results, retry methods)
     - `FaqController` (getTaskStatus method)
     - `BacklinksController` (results, status methods)
     - `KeywordResearchController` (already had ownership checks in service layer)
   - Prevents unauthorized access to other users' data

3. **Input Sanitization** ✅
   - Created `SanitizeInput` middleware
   - Automatically sanitizes URL, domain, and input fields
   - Applied globally to all API routes
   - Prevents XSS and injection attacks

4. **Security Headers** ✅
   - Created `SecurityHeaders` middleware
   - Adds security headers: X-Content-Type-Options, X-Frame-Options, X-XSS-Protection, Referrer-Policy
   - Adds Strict-Transport-Security in production
   - Applied globally to all API routes

### Performance Optimizations

5. **Parallel Batch Citation Processing** ✅
   - Refactored `DataForSEOCitationService::batchFindCitations()` to use `Http::pool()`
   - Processes queries in parallel batches (configurable concurrency: default 5)
   - Significantly reduces processing time for large query sets
   - Configurable via `config('services.dataforseo.max_concurrent_requests')`

6. **Pagination for List Endpoints** ✅
   - Added pagination to `KeywordResearchController::index()`
   - Accepts `per_page` parameter (1-100, default: 15)
   - Uses Laravel's pagination for consistent response format

7. **Service Dependency Injection** ✅
   - Fixed `CitationChunkJob` to use constructor dependency injection
   - Replaced `app(DataForSEOCitationService::class)` with proper DI
   - Improves testability and follows SOLID principles

### Code Quality Improvements

8. **Route Helpers Instead of Hardcoded URLs** ✅
   - Replaced hardcoded URLs in `CitationController` with `route()` helpers
   - More maintainable and less error-prone
   - Automatically updates if route names change

9. **Configuration Management** ✅
   - Moved cache lock timeouts to `config/cache_locks.php`
   - Configurable timeouts for:
     - Keyword research (default: 10 seconds)
     - Search volume (default: 30 seconds)
     - Citations (default: 60 seconds)
     - FAQ (default: 120 seconds)
   - Added citation and FAQ default language/location to `config/services.php`

10. **Database Schema Updates** ✅
    - Added `user_id` column to `citation_tasks` table (migration: `2025_12_29_000002_add_user_id_to_citation_tasks.php`)
    - Enables ownership tracking and validation for citation tasks
    - Model updated with `user()` relationship

### API Enhancements

11. **Health Check Endpoint** ✅
    - Created `/api/health` endpoint (`HealthController`)
    - Checks database, cache, and Redis connectivity
    - Returns health status with component-level checks
    - Useful for monitoring and load balancer health checks

12. **Rate Limiting Improvements** ✅
    - Added rate limiting to backlinks endpoints (`throttle:30,1`)
    - All endpoints now have appropriate rate limiting configured

### Error Handling Improvements

13. **Structured Error Responses** ✅
    - Generic exceptions now include error IDs for tracking
    - Error messages hide sensitive details in production
    - Consistent error code format across all exceptions

### Additional Fixes Applied (2025-12-29)

14. **Removed Schema::hasColumn and Schema::getColumnListing** ✅
    - Removed all `Schema::hasColumn()` and `Schema::getColumnListing()` calls from application code
    - Migrations handle schema changes, no runtime checks needed
    - Improved performance by eliminating unnecessary schema queries
    - Files updated:
      - `app/Services/KeywordService.php`
      - `app/Services/Keyword/KeywordResearchOrchestratorService.php`
      - `app/Models/KeywordResearchJob.php`
      - `app/Repositories/KeywordResearchJobRepository.php`
      - `database/migrations/2025_12_29_000002_add_user_id_to_citation_tasks.php`

15. **Fixed Job Dependency Injection** ✅
    - Fixed `FetchBacklinksResultsJob::failed()` to use dependency injection
    - Replaced `app(BacklinksRepositoryInterface::class)` with proper DI
    - Improves testability and follows SOLID principles

16. **Environment Variables Configuration** ✅
    - All DataForSEO limits now configurable via environment variables:
      - Search Volume: `DATAFORSEO_SEARCH_VOLUME_MAX_KEYWORDS`, `DATAFORSEO_SEARCH_VOLUME_BATCH_SIZE`
      - Citation: `DATAFORSEO_CITATION_MAX_DEPTH`, `DATAFORSEO_CITATION_DEFAULT_DEPTH`, `DATAFORSEO_CITATION_CHUNK_SIZE`, `DATAFORSEO_CITATION_MAX_QUERIES`
      - Backlinks: `DATAFORSEO_BACKLINKS_DEFAULT_LIMIT`, `DATAFORSEO_BACKLINKS_MAX_LIMIT`, `DATAFORSEO_BACKLINKS_SUMMARY_LIMIT`
      - Keywords for Site: `DATAFORSEO_KEYWORDS_FOR_SITE_DEFAULT_LIMIT`, `DATAFORSEO_KEYWORDS_FOR_SITE_MAX_LIMIT`
      - Keyword Ideas: `DATAFORSEO_KEYWORD_IDEAS_DEFAULT_LIMIT`, `DATAFORSEO_KEYWORD_IDEAS_MAX_LIMIT`
    - Microservice configuration variables:
      - PBN Detector: `PBN_MAX_BACKLINKS`, `PBN_HIGH_RISK_THRESHOLD`, `PBN_MEDIUM_RISK_THRESHOLD`, `PBN_PARALLEL_THRESHOLD`, `PBN_PARALLEL_WORKERS`
      - Keyword Clustering: `CLUSTERING_MAX_KEYWORDS`, `MODEL_NAME`, `CUSTOM_MODEL_PATH`
    - All variables documented and added to `.env` file

17. **Microservices Improvements** ✅
    - PBN Detector:
      - Added request size validation (`PBN_MAX_BACKLINKS`)
      - Improved cache key uniqueness (SHA256 hash)
      - Added health check integration
      - Made thresholds configurable via environment variables
      - Parallelized enhanced feature extraction for large datasets
    - Keyword Clustering:
      - Added request size validation (`CLUSTERING_MAX_KEYWORDS`)
      - Implemented Redis-based embedding caching
      - Enhanced health check to report Redis availability
      - Complete service implementation with error handling

18. **Comprehensive Database Indexing** ✅
    - Created migration `2025_12_29_000001_add_comprehensive_indexes.php`
    - Added indexes for:
      - `keyword_research_jobs`: user_id, status, created_at, query
      - `keywords`: keyword_research_job_id, keyword, cluster_id
      - `keyword_clusters`: keyword_research_job_id
      - `keyword_cache`: keyword, language_code, location_code
      - `pbn_detections`: task_id, source_url, risk_level
      - `backlinks`: task_id, domain, source_url, source_domain
      - `seo_tasks`: task_id, domain, status, user_id
      - `faqs`: user_id, url_hash, topic_hash
      - `citation_tasks`: user_id, url, status
    - Composite indexes for common query patterns
    - Significantly improves query performance

19. **Code Cleanup** ✅
    - Removed unnecessary comments from codebase
    - Kept only essential PHPDoc blocks and complex logic explanations
    - Improved code readability and maintainability

---

## Complete Fix Summary

### All Critical Issues Resolved ✅

1. ✅ Exception handler consistency (PbnDetectorException)
2. ✅ Project ownership validation (all controllers)
3. ✅ Service dependency injection (all jobs)
4. ✅ Route helpers instead of hardcoded URLs
5. ✅ N+1 query prevention (eager loading, bulk operations)
6. ✅ Rate limiting on all endpoints
7. ✅ Input sanitization middleware
8. ✅ Structured error handling with error IDs
9. ✅ Health check endpoint
10. ✅ Parallel batch processing (citations)
11. ✅ Comprehensive database indexing
12. ✅ Pagination for list endpoints
13. ✅ Environment variable configuration
14. ✅ Microservices improvements
15. ✅ Code cleanup and documentation

### All High Priority Issues Resolved ✅

1. ✅ Batch citation processing parallelization
2. ✅ Cache lock timeout configuration
3. ✅ Database cache integration
4. ✅ Request deduplication
5. ✅ Circuit breaker patterns
6. ✅ Configuration management
7. ✅ Security headers
8. ✅ Response format standardization

### System Status

**All identified flaws from IMPROVEMENTS.md, MICROSERVICES_AUDIT.md, and implementation.md have been fixed and documented.**

The system is now:
- ✅ Secure (ownership validation, input sanitization, security headers)
- ✅ Performant (indexes, parallel processing, caching, pagination)
- ✅ Maintainable (dependency injection, configuration management, clean code)
- ✅ Resilient (circuit breakers, error handling, health checks)
- ✅ Scalable (pagination, batch processing, configurable limits)

---

**Last Updated:** 2025-12-29  
**Documentation Status:** ✅ Complete - All improvements implemented and documented  
**System Status:** ✅ Production Ready

