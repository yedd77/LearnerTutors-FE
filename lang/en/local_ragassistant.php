<?php

// LearnerTutors landing page
$string['pluginname'] = 'LearnerTutors'; 
$string['dashboardheading'] = 'LearnerTutors'; 
$string['uploadCardTitle'] = 'Manage Course Materials'; 
$string['uploadCardDesc'] = 'Upload and manage learning materials available to the assistant for this course.'; 
$string['uploadButton'] = 'Manage documents'; 
$string['askassistant'] = 'Ask the LearnerTutors'; 
$string['chatintro'] = 'Ask questions about permitted course materials and review cited sources.'; 
$string['openchat'] = 'Open chat';

// Upload document form components
$string['submitdocument'] = 'Upload course material document';
$string['submitdocumentdesc'] = 'Add the learning materials to become context for the assistant';
$string['documentfile'] = 'Document file(s)';
$string['documentfile_help'] = 'Select PDF, DOCX, PPTX, TXT, or Markdown file(s), up to 20 MB. Maximum 20 files per upload.';
$string['documentreviewtitle'] = 'Review files';
$string['documentreviewdesc'] = 'Review and add additional notes for each files';
$string['uploadfilesbutton'] = 'Upload all files';
$string['lecturernote'] = 'Lecturer note';
$string['retrieval'] = 'Retrieval';
$string['uploadedDocuments'] = 'Manage uploaded documents';
$string['manageUploaded'] = 'Disable or delete uploaded documents. Disabling a document prevents it from being used by the assistant, but does not delete it. Deleting a document removes it permanently.';

// Chatbot page components
$string['chatwelcome'] = 'Ask questions about your course materials. Answers will eventually include references to source documents.';
$string['chatprivacyinfo'] = 'Your question and the text retrieved from documents available to you in this course are sent to our configured AI model. Do not submit passwords or other sensitive personal information.';
$string['chatinputhint'] = 'AI can make mistake. Do verify important information against the cited source.';
$string['sendquestion'] = 'Send';
$string['questionplaceholder'] = 'For example: Summarise the main concepts in Week 3 notes.';
$string['yourquestion'] = 'Your question';
$string['sourcepage'] = 'Page / slide';
$string['sourcesheading'] = 'Sources used';
$string['unknown'] = 'Unknown';
$string['questionrequired'] = 'Enter a question with at least two characters.';
$string['questiontoolong'] = 'Keep your question under 4,000 characters.';


// Course chat settings card (on the course materials page)
$string['chatsettingstitle'] = 'Chat Settings';
$string['chatsettingsdesc'] = 'Choose whether enrolled students may use the AI chat in this course. Changes apply to this course only.';
$string['allowstudentchat'] = 'Allow students to use the AI chat';
$string['allowstudentchat_desc'] = 'When disabled, students cannot open or submit questions to the assistant in this course. Educators with document-management permission remain able to use it.';
$string['policysaved'] = 'Course chat settings saved.';

// Utility strings
$string['backtodashboard'] = 'Back to LearnerTutors';


$string['uploadfailed'] = 'The file was not stored. Please try again.';
$string['uploadnotpermitted'] = 'You do not have permission to upload documents in this course.';
$string['documentname'] = 'Document';
$string['visibility'] = 'Visi   bility';
$string['processingstatus'] = 'Processing status';
$string['retrieval'] = 'Retrieval';
$string['dateuploaded'] = 'Date uploaded';
$string['personal'] = 'Uploader only';
$string['course'] = 'Course participants';
$string['pendingfilename'] = 'Uploaded file';
$string['status_pending'] = 'Pending indexing';
$string['status_processing'] = 'Processing';
$string['status_ready'] = 'Ready';
$string['status_failed'] = 'Failed';
$string['nodocuments'] = 'No documents have been uploaded for this course yet.';
$string['nogenerateddocuments'] = 'No generated documents are available yet.';



$string['chatdisabledreason'] = 'Chat is disabled in site settings. Configure and test FastAPI and the selected AI provider before enabling it.';


$string['apikeymissing'] = 'The RAG API key is not configured. Ask a site administrator to configure it.';
$string['backendconnectionfailed'] = 'Could not connect to the RAG backend. Check that FastAPI is running and the base URL is correct.';
$string['backendrequestfailed'] = 'The RAG backend rejected the request: {$a}';
$string['backendinvalidresponse'] = 'The RAG backend returned an invalid response.';
$string['filetempfailed'] = 'Moodle could not prepare the stored document for indexing.';
$string['storedfilemissing'] = 'The stored document file could not be found.';
$string['indexsuccess'] = 'Document indexed successfully ({$a} text chunks).';
$string['indexdocument'] = 'Index';
$string['actions'] = 'Actions';

$string['answerfailed'] = 'The assistant could not answer right now. Check that the backend and configured AI provider are available, then try again.';

$string['notavailable'] = 'Not available';



$string['studentchatdisabled'] = 'The lecturer has disabled student access to the AI chat for this course.';

// Plugin Settings compoments
$string['api_base_url'] = 'FastAPI base URL';
$string['api_base_url_desc'] = 'Base URL of your RAG backend, without an API path.';
$string['api_key'] = 'FastAPI API key';
$string['api_key_desc'] = 'API key used to authenticate requests to the RAG backend.';
$string['reindexpending'] = 'The embedding configuration has changed. Course documents are being reindexed so their vectors remain compatible. Chat-generation model changes do not require reindexing. Please try again after Moodle scheduled tasks have completed.';



// Settings page components
// Plugin Settings compoments
$string['settingsheading'] = 'Plugin settings';
$string['settingsintro'] = 'Configure the local FastAPI service. Do not expose this service publicly without authentication and network restrictions.';
$string['apibaseurl'] = 'Server API URL';
$string['apibaseurl_desc'] = 'URL used for API call to the server.';
$string['apikey'] = 'Server API key';
$string['apikey_desc'] = 'Enter the server API key matching the generated key created during server setup.';
$string['enablechat'] = 'Enable AI chat';
$string['enablechat_desc'] = 'Global setting to enable AI chat feature. If unchecked, the AI chat feature will be disabled for all courses but indexing and document management will still be available.';
// LLM config settings 
$string['llm_heading'] = 'LLM configuration';
$string['llm_heading_desc'] = 'Configure the LLM settings for the RAG system.';
$string['llmprovider'] = 'LLM provider';
$string['llmprovider_desc'] = 'Select the LLM provider used for chat generation.';
$string['llm_model'] = 'LLM model';
$string['llm_model_desc'] = 'Enter the LLM model identifier corresponding to the selected provider.';
$string['provider_api_key'] = 'LLM API key';
$string['provider_api_key_desc'] = 'Enter the API key for the provider selected above (OpenAI or Google Gemini).';
// Image LLM config settings
$string['image_heading'] = 'LLM for Image generation configuration';
$string['image_heading_desc'] = 'Configure the LLM for image generation settings for the RAG system.';
$string['image_provider'] = 'LLM for Image provider';
$string['image_provider_desc'] = 'Select the LLM provider for image generation.';
$string['image_model'] = 'Image generation model';
$string['image_model_desc'] = 'Enter the model identifier for the selected LLM for image generation provider.';
$string['reuse_chat_api_key'] = 'Reuse chat provider API key';
$string['reuse_chat_api_key_desc'] = 'If the provider for image generation is the same as the chat provider, and use the same API key, you can reuse the chat provider API key. ';
$string['image_provider_api_key'] = 'Image generation provider API key';
$string['image_provider_api_key_desc'] = 'Enter the API key for the selected image generation provider.';
// Local LLM fallback
$string['fallback_heading'] = 'Local LLM fallback';
$string['fallback_heading_desc'] = 'Configure the local Ollama model. This model will be run inside local environments when the primary cloud model call fails. The provider is fixed to Ollama.';
$string['fallback_model'] = 'Fallback model';
$string['fallback_model_desc'] = 'Enter the Ollama model identifier installed on the server';
// Configuration health check
$string['configurationhealth_heading'] = 'Configuration Health Checks';
$string['configurationhealth_heading_desc'] = 'Check the connectivity of the RAG system and the availability of the configured LLM providers and models. Before the changes are saved, run the tests and fix any failed checks. ';
$string['configurationhealth'] = 'Test RAG configuration';
$string['configurationhealth_desc'] = 'Check the connectivity of the RAG system and the availability of the configured LLM providers and models.';
$string['healthcheck_test_server'] = 'Application Server';
$string['healthcheck_test_llm'] = 'LLM provider and model';
$string['healthcheck_test_image'] = 'Image generation provider and model';
$string['healthcheck_test_fallback'] = 'Local fallback model (Ollama)';



$string['encryptionkeymissing'] = 'Moodle could not access its protected data directory to initialize secure credential storage. Check the dataroot path and PHP write permissions.';
$string['encryptionkeyinvalid'] = 'The configured Moodle credential encryption key is invalid, or the saved key file is damaged. Restore the original key from backup; do not generate a replacement while encrypted credentials exist.';
$string['secretencryptionfailed'] = 'Moodle could not encrypt the application API key.';
$string['secretdecryptionfailed'] = 'Moodle could not decrypt the stored application API key. Verify that the original Moodle credential encryption key or moodledata key file has been restored.';
$string['apikeytoolong'] = 'Use an application API key with at least 32 characters.';
$string['secretstoragefailed'] = 'The application API key could not be stored securely.';
$string['apikey_desc_secure'] = 'Enter the shared key matching RAG_API_KEY on FastAPI. The field is blank to avoid sending the saved key to your browser. Leave blank to keep the current key. Moodle encrypts the value at rest using an automatically generated key stored in the protected moodledata directory. Advanced deployments may provide LOCAL_RAGASSISTANT_ENCRYPTION_KEY or $CFG->local_ragassistant_encryption_key.';
$string['testconnection'] = 'Test FastAPI connection';
$string['testconnectionheading'] = 'Test FastAPI authentication';
$string['testconnectionintro'] = 'Send a server-to-server authentication test using the configured URL and shared key. The key is never displayed.';
$string['testconnectionsuccess'] = 'FastAPI connection and API-key authentication succeeded.';
$string['testconnectionfailed'] = 'The connection test failed: {$a}';
$string['backendhttpsrequired'] = 'The FastAPI URL must use HTTPS. HTTP is allowed only for localhost or loopback development addresses.';

// AI provider credential settings


// Combined provider credential setting
$string['providerkeynotrequired'] = 'Ollama does not require an API key. Select OpenAI or Google Gemini to save a provider credential.';
$string['providerkeytoolong'] = 'Use a provider API key with at least 20 characters.';


// Encrypted credential status labels (never display credential values).
$string['credentialconfigured'] = 'Configured — saved credential is available';
$string['credentialnotconfigured'] = 'Not configured — enter an API key';
$string['credentialnotrequired'] = 'Not required for the selected provider';
$string['credentialverificationfailed'] = 'A saved credential exists but could not be verified. Check the encryption key and restore it from backup if necessary.';
$string['credentialinputplaceholder'] = '*******************************';



//settings
$string['runconfigurationtests'] = 'Run Configuration Tests';
$string['healthcheck_apikeymissing'] = 'No application API key is saved. Enter it above and save the settings first.';
$string['healthcheck_llmmodelmissing'] = 'Enter a chat model before running the tests.';
$string['healthcheck_needsmodel'] = 'Not tested because the backend needs a chat model to run any provider test.';
$string['healthcheck_notreported'] = 'The backend did not report a result for this test.';
$string['healthcheck_requestfailed'] = 'Unable to complete the configuration tests. Check your settings and backend connection.';
$string['healthcheck_requires_backend'] = 'Not tested because the application server check failed.';
$string['healthcheck_server_fail'] = 'Could not reach the backend. Check that FastAPI is running and the saved backend URL is correct.';
$string['healthcheck_server_pass'] = 'Reachable, and the application API key was accepted.';
$string['healthcheck_status_fail'] = 'Failed';
$string['healthcheck_status_pass'] = 'Passed';
$string['healthcheck_status_skipped'] = 'Skipped';







$string['healthcheck_status_idle'] = 'Not tested';
$string['healthcheck_status_testing'] = 'Testing...';
$string['healthcheck_running'] = 'Running tests...';
$string['healthcheck_summary'] = '{$a->pass} passed, {$a->fail} failed, {$a->skipped} skipped';
$string['healthcheck_test_cron'] = 'Background tasks (cron)';
$string['healthcheck_testing_row'] = 'Testing {$a}...';
$string['healthcheck_cron_never'] = 'Moodle cron has never run. Document indexing runs as a background task, so uploads will not be processed until cron is set up.';
$string['healthcheck_cron_overdue'] = 'Moodle cron last ran {$a} ago. Document indexing waits until cron runs again, so check the cron schedule.';
$string['healthcheck_cron_failing'] = '{$a} document indexing task(s) are failing and waiting to retry. Check Site administration > Server > Tasks > Ad hoc tasks.';
$string['healthcheck_cron_waiting'] = '{$a} document indexing task(s) have been waiting for more than an hour.';
$string['healthcheck_cron_pass'] = 'Cron last ran {$a} ago, and no indexing tasks are stuck.';
$string['healthcheck_cron_error'] = 'Could not check the background tasks.';
$string['healthcheck_keysaved'] = 'A key is saved for this provider. Leave blank to keep it.';
$string['healthcheck_keynone'] = 'No key is saved for this provider yet.';
$string['healthcheck_savelocked'] = 'Run the configuration tests below. Every check must pass before these changes can be saved.';
$string['healthcheck_elapsed'] = '{$a} s elapsed. AI checks can take up to a minute.';
$string['healthcheck_stale'] = 'Settings changed. Run the tests again.';
$string['healthcheck_notneeded'] = 'Not needed: chat already uses Ollama.';
$string['assistantname'] = 'LearnerTutors';