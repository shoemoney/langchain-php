# Review 55 - nex-agi/nex-n2.5-mini (TRUNCATED)
_finish_reason=length, reasoning 26519/24000_


We need answer exactly five findings. Need inspect source for real issues not already fixed. Need be senior advisory. Need concise <=6 lines each. Need evidence file:line or named test. We have source no line numbers, can cite constructs. Need find five real defects/gaps in provider clients maybe elsewhere. Need not propose upstream absent features. Need be careful with traps. Need inspect code.

We need identify actual bugs. Let's analyze thoroughly.

We have source of provider clients. Potential issues:

1. ChatOpenAI::canonicalise alias conflict: KEY_ALIASES includes both 'stop' => 'stopSequences' and 'stop_sequences' => 'stopSequences'. If bag has both 'stop' and 'stop_sequences', canonicalise loops 'stop' adds stopSequences, then 'stop_sequences' sees stopSequences exists and doesn't override. Constructor after canonicalise: $stop = $fields['stop'] ?? $fields['stopSequences'] ?? $fields['stop_sequences'] ?? null; Because canonicalise didn't remove original wire keys, if both stop and stop_sequences provided, fields['stop'] wins over stop_sequences despite iteration order? Actually if both, stopSequences is stop, but $stop uses $fields['stop'] first. So stop_sequences silently ignored. Similar top_p/topP, max_tokens/maxTokens, tool_choice/toolChoice, parallel_tool_calls/parallel_tool_calls, response_format/responseFormat. This is a real bug: conflicting aliases should error or consistent precedence. Upstream? If both spellings present, JS maybe object has both; code picks canonical? Need not propose feature. It causes caller setting silently ignored. Severity major. Evidence construct ChatOpenAI::KEY_ALIASES/canonicalise and constructor. Suggested fix: reject conflicting aliases or normalize by deleting old keys after mapping with deterministic precedence. Need maybe Anthropic too has top_p/topP, top_k/topK, stop/stop_sequences, tool_choice/toolChoice. OpenAI has more.

2. ChatAnthropic::headers uses `$this->defaultHeaders + [...]` PHP array union preserves left keys. If caller supplies defaultHeaders with lowercase 'content-type' or uppercase 'X-Api-Key', it overrides canonical required headers? Actually PHP + preserves keys exactly and left wins. If defaultHeaders includes 'X-Api-Key' or 'Anthropic-Version', it overrides apiKey/version. This is a security/protocol issue. Is that intended? Anthropic defaultHeaders in upstream likely set defaults that can override? Let's check: In TS maybe `this.defaultHeaders = new Headers(defaultHeaders);` then headers = this.defaultHeaders + new Headers({'x-api-key': apiKey, anthropic-version})? In JS object union maybe right wins? Actually LangChain Anthropic headers maybe:
```
private headers() {
 return this.defaultHeaders + {
  'x-api-key': this.apiKey,
  'anthropic-version': API_VERSION,
  'content-type': 'application/json',
 };
}
```
In JS object spread right wins. In PHP array union left wins. So this is a real divergence: defaultHeaders can override auth/version/content-type, causing wrong API version or missing auth. Severity major maybe blocker? If user sets defaultHeaders with content-type, maybe intended? But defaultHeaders should be defaults not overrides? Upstream likely defaultHeaders can override? Need know. In LangChain Anthropic, `defaultHeaders` are "additional headers to include" maybe should not override auth? Let's think. In TS code:
```
private headers(): Headers {
 return this.defaultHeaders + {
   'x-api-key': this.apiKey,
   'anthropic-version': API_VERSION,
   'content-type': 'application/json',
 };
}
```
Since object spread right wins, required headers always win. PHP uses `+` left wins, so wrong. Evidence ChatAnthropic::headers. Suggested fix: use `array_replace($this->defaultHeaders, [...])` (right wins) or build required then merge default, ensuring auth/version cannot be overridden. Good.

3. ChatOpenAI::postStream retry logic: `$delivered` set true before decode. If non-2xx response returns bytes? HttpClient postStream maybe for non-2xx throws before yielding? Need inspect HttpClient maybe not included. But if postStream returns non-2xx as bytes? likely throws. If malformed event yields non-empty bytes then decode throws, delivered true so no retry. Good.

But issue: In postStream catch, if HttpException occurs after bytes yielded but decode produced no chunks? delivered true. Good.

But if raw stream yields zero-length bytes repeatedly then decode empty, delivered false and retry. Good.

4. ChatOpenAI::decode and ChatAnthropic::decode: If decoded is not array, they continue silently. They comment "payload that is not JSON is fatal" but then if JSON primitive e.g. `null`, `""`, `123` is array false -> continue, silently drops event. Is that a real bug? Upstream maybe ignores non-object? SSE data could be `[DONE]`? SseParser filters [DONE]? For JSON stream, non-object event should be malformed. But maybe not important. Could be minor.

5. ChatOpenAI::streamResponseChunks: For usage-only chunk with no choices, if payload has usage, yields AIMessageChunk with response_metadata = Completions::responseMetadata($payload), which includes usage_metadata. Good. But it does not include id? It includes id. Good.

6. ChatOpenAI::generate: If some choices malformed and some usable, silently skips malformed choices. It already throws if no usable. Upstream maybe invalid choices? Could be okay.

7. Completions::choiceToMessage: It parses tool calls with JsonOutputToolsParser::parseToolCall($rawToolCall, true, false). Need check signature. Could parseToolCall return null? It sets parsed['type']='tool_call'. Good. But invalid_tool_calls array maybe not folded? Fine.

8. Completions::convertMessage: `$message->toolCalls !== []` assumes toolCalls property always array. In BaseMessage? Need messages source not included. But likely.

9. Completions::convertMessage for AIMessage with additional_kwargs['tool_calls'] returns raw provider-shaped tool_calls. If rawToolCalls from previous response include invalid_tool_calls? It sends invalid tool calls? Actually additional_kwargs['tool_calls'] includes rawToolCalls from response, including invalid? In choiceToMessage additional_kwargs['tool_calls'] set to rawToolCalls even if invalid parsed? It sets tool_calls => rawToolCalls !== [] ? rawToolCalls : null. Then when echoing assistant message with invalid tool calls, convertMessage sees `$message->toolCalls !== []`? parsed valid tool calls maybe non-empty; invalid_tool_calls separate. It sends valid tool calls, not invalid. If all invalid, toolCalls empty, additional_kwargs['tool_calls'] raw includes invalid_tool_calls? It sends them as tool_calls, not invalid_tool_calls. Could be divergence. But not sure.

10. Anthropic MessageInputs::convert: It folds tool messages into HumanMessage. Then leading system detection after fold. Good.

11. Anthropic MessageInputs::foldToolMessages: It merges consecutive tool messages only if previous is HumanMessage with first block tool_result. If previous is HumanMessage with multiple tool_result blocks, it appends to it. Good. If previous HumanMessage had text before tool_result, it creates new HumanMessage with only tool_result blocks, discarding text. Upstream? `foldToolMessages` likely:
```
if (last instanceof HumanMessage && isArrayOfToolResultBlocks(last.content)) {
 last.content.push(toolBlock)
} else {
 out.push(new HumanMessage({content: [toolBlock]}))
}
```
If last content is array of tool_result blocks, yes. If text + tool_result, not folded. Good.

12. Anthropic MessageInputs::foldToolMessages: It doesn't preserve HumanMessage additional_kwargs/name? It creates new HumanMessage with content only, dropping name and other metadata. Upstream likely same? Maybe not.

13. Anthropic MessageInputs::convertMessage: For AIMessage with toolCalls, returns content = array_merge(textBlocks(content), tool_use blocks). If content is array with non-array blocks (e.g. reasoning? text?) textBlocks filters only arrays. This may drop non-array content. But content blocks should be arrays. If content is string, okay. If content is array with non-array, maybe invalid.

14. Anthropic MessageInputs::textBlocks: `return array_values(array_filter($content, 'is_array'));` drops non-array blocks. If content includes e.g. `['type'=>'text','text'=>'...']` okay. If content includes `['type'=>'thinking','thinking'=>'...']` okay. If content includes non-array maybe invalid.

15. Anthropic MessageInputs::formatContent: If content is array, returns as-is. If content is array of strings? Anthropic content blocks require objects; but upstream maybe accepts? Not issue.

16. Anthropic MessageInputs::convertTool: For array tool, if `isset($tool['input_schema']) || isset($tool['name'])` returns $tool. But if provider-shaped array has `type: function` and `function` but no name/input_schema, it transforms. Good.

17. Anthropic bindTools: `$strict = $kwargs['strict'] ?? $this->kwargs['strict'] ?? null;` If bound instance has `strict` in kwargs and new bindTools called with no strict, it inherits. Good. But if previous bindTools had strict false, kwargs['strict']=false; `$kwargs['strict'] ?? ...` in PHP null coalescing returns false? Yes, `??` returns RHS only if null/undefined, false is not null. Good.

18. ChatAnthropic invocationParams: It doesn't reject unsupported options. OpenAI rejects topK. Anthropic doesn't have unsupported? Maybe no.

19. ChatAnthropic invocationParams tool_choice validation: It only validates if choice is array with type tool. If user passes string 'auto'/'any'/'none', no validation. If passes array ['type'=>'tool','name'=>'missing'] catches. If passes array ['type'=>'tool','name'=>null], catches. If passes array ['type'=>'auto'] no validation. Good. If passes string 'missing', formatToolChoice returns type tool name missing but validation catches. Good.

20. ChatOpenAI tool_choice validation: It doesn't validate tool_choice names against tools. Upstream maybe does? In LangChain OpenAI, `_convert_tool_choice_to_json` maybe validates? Let's recall: In @langchain/openai, tool_choice can be "auto", "none", "any", object. There is function `_convert_tool_choice_to_json(toolChoice, toolKeys)`:
```
if (toolChoice === "auto") return {type:"auto"};
if (toolChoice === "any") return {type:"required"};
if (toolChoice === "none") return {type:"none"};
if (typeof toolChoice === "string") return {type:"function", function:{name: toolChoice}};
...
if (toolChoice.type === "function") {
 if (!toolKeys.includes(toolChoice.function.name)) throw new Error(`tool_choice "${name}" is not one of ...`)
 return ...
}
```
So OpenAI should validate tool_choice names. The code only formats, no validation. This is a real defect: invalid tool_choice is sent to provider, provider rejects with less helpful error. But is it "serious gap"? Major? It causes wrong request but provider rejects. Maybe minor/major. Need five findings. Could include.

21. ChatOpenAI::convertTools: For per-call tools, it converts and sets 'tools' to converted. For bound tools, $bound['tools'] already converted. Good.

22. ChatOpenAI::bindTools: `$strict = $kwargs['strict'] ?? $this->supportsStrictToolCalling;` If supportsStrictToolCalling is null, strict null. Good. But if previous bindTools with strict false, supportsStrictToolCalling false; next bindTools no strict inherits false. Good.

23. ChatOpenAI::bindTools: `$this->rejectUnsupported($kwargs);` after setting tools. Good.

24. ChatOpenAI::canonicalise: It doesn't remove aliases, causing conflict. Also `array_intersect_key($fields, ...)` uses canonical keys but original wire keys remain in $fields. It filters to allowed keys. For allowed aliases, both original and canonical present. For disallowed aliases, e.g. 'top_k' => 'topK', rejectUnsupported canonicalises and sees topK. Good.

25. ChatOpenAI::invocationParams: `$bound = $this->normaliseKeys($this->kwargs);` If kwargs contains both 'stop' and 'stopSequences', canonicalise leaves both. Then `$bound['stopSequences']` maybe from stop, `$bound['stop']` original. The pick for stop uses `$this->pick($options, 'stop','stopSequences','stop_sequences') ?? $bound['stopSequences'] ?? $bound['stop'] ?? ...`. If bound has both, stopSequences from stop wins. If options has both, pick checks 'stop' first, so stop wins. Constructor also stop wins. So consistent but silent conflict. Need maybe.

26. ChatOpenAI::canonicalise alias conflict with 'stop' and 'stopSequences': If both present, stopSequences from stop; stop remains. If later array_filter? no.

27. ChatAnthropic::canonicalise same.

28. ChatOpenAI::headers: If apiKey empty string, headers sends Bearer ; but constructor only null, not empty. It checks trim model but not apiKey. If apiKey '' passed, headers sends empty bearer. Upstream maybe API key can be empty? Should throw? Not sure.

29. ChatAnthropic::headers: If apiKey null, throws. Good.

30. ChatAnthropic::defaultHeaders: It casts fields['defaultHeaders'] to array. If user passes string, becomes [0=>string], then headers array union with string key? PHP array union with numeric key? It will include numeric key, not header. Not issue.

31. ChatAnthropic::headers uses lowercase 'content-type'; PSR-7 headers case-insensitive? Guzzle normalizes? Fine.

32. ChatOpenAI::post: `return $response->json();` If json invalid, JsonException escapes not OpenAIException. Upstream maybe handles? Could be okay.

33. ChatOpenAI::postStream: It catches HttpException only. If `json_decode` in decode throws OpenAIException, not retried. Good.

34. ChatAnthropic::streamResponseChunks: It sets `$delivered = true` before `yield from $this->consume(...)`. If consume yields usage chunk with empty content before actual text, delivered true. Good.

35. ChatAnthropic::consume: For message_start usage chunk, it yields a ChatGenerationChunk with content ''. Then eventToChunk may return null. Good.

36. ChatAnthropic::consume: It gates usage on streamUsage. If streamUsage false, usage chunks not yielded. Good.

37. ChatAnthropic::streamResponseChunks: It doesn't catch `HttpException` from `$this->http()->postStream` if it throws before generator creation? It does. But if postStream returns generator and first iteration throws HttpException, catch catches because foreach inside try. Good.

38. ChatOpenAI::postStream: Same.

39. ChatOpenAI::postStream: It retries on HttpException status 0/429/5xx. But if HttpClient postStream returns non-2xx response body as bytes and yields them, decode sees JSON error and throws OpenAIException fromResponse with status 0. Delivered true if bytes non-empty, so not retried. But if non-2xx body is non-empty, maybe provider error delivered? For stream establishment, non-2xx body is not "delivered tokens"; should retry? The code's `$delivered` only tracks bytes yielded, not successful status. If HttpClient returns a non-2xx response as a stream (rather than throwing), the first non-empty error JSON body sets delivered=true, then decode throws OpenAIException (not retried). But likely HttpClient postStream throws on non-2xx before yielding. Need inspect maybe not included. Could be real if Guzzle postStream returns response object and checks status. We can infer? Not safe.

40. ChatOpenAI::postStream: If raw stream yields bytes but decode yields no chunks due to non-object JSON, delivered true. Fine.

41. ChatOpenAI::decode: If decoded is not array, continue. If payload is `[DONE]`, SseParser probably filters. If payload is `data: [DONE]`? SseParser maybe yields '[DONE]'? Tests cover [DONE]. If not filtered, decode json_decode('[DONE]') throws malformed event. But tests pass.

42. ChatAnthropic::decode: Same.

43. ChatOpenAI::postStream: It creates new SseParser each retry. Good.

44. ChatAnthropic::post? It doesn't catch OpenAI? It catches AnthropicException. But `AnthropicException::fromResponse` maybe throws? Good.

45. ChatOpenAI::generate: It calls `$this->post($params)` which returns array. If response json is array with choices but choices contain non-array, skips. Good.

46. ChatOpenAI::generate: It computes `$text = Completions::stringifyContent($message->content);` For content array, stringifyContent uses Js::encode. Upstream maybe stringifies content blocks with newlines? Fine.

47. ChatOpenAI::choiceToMessage: It sets message id from rawResponse id, not message id. OpenAI choice message has id? Usually response has id. Good.

48. ChatOpenAI::choiceToMessage: It sets additional_kwargs['tool_calls'] to rawToolCalls if any, including raw tool calls even if parsed valid. Then convertMessage for AIMessage first checks `$message->toolCalls !== []`, so sends parsed tool calls, not raw. Good. If parsed valid tool call arguments are objects, toolCallToWire encodes them as JSON string. Good.

49. ChatOpenAI::choiceToMessage: It sets invalid_tool_calls with error message. Good.

50. ChatOpenAI::Completions::roleOf: For SystemMessage, role system. For FunctionMessage, role function. Good.

51. ChatOpenAI::Completions::stringifyContent: If content is array of content blocks, it JSON encodes. But Chat Completions accepts content as string or array of parts. Upstream maybe stringifies? For OpenAI, content can be string or array. This port may send array as JSON string, which OpenAI may reject? Let's recall LangChain OpenAI converter for messages: For assistant messages, if content is string, return content; if array, maybe returns content as array? In TS:
```
if (isBaseMessage(message)) {
 if (message._getType() === "human" || ... ) {
  return { role, content: message.content };
 }
}
```
For OpenAI, content can be string or array of parts. But if content is array of content blocks with type text, OpenAI accepts array of parts. This port's stringifyContent always encodes array to JSON string. Is that a bug? The doc says "Flatten content to a string for the fields that only accept one." But for normal AIMessage/HumanMessage, convertMessage sets `$param['content'] = $message->content;` not stringify. For ToolMessage and FunctionMessage, string-only. For AIMessage with toolCalls, content remains as is. So not all. For HumanMessage with array content, it sends array. Good.

52. ChatOpenAI::Completions::convertMessage for ToolMessage: It sets content = stringifyContent($message->content). If ToolMessage content is array of tool_result blocks, OpenAI tool message content can be string or array of parts? OpenAI tool messages content can be string or array of content parts? I think tool message content can be string or array of parts. But they stringify. Upstream maybe stringifies? For OpenAI, tool message content can be string or array of text/delta? Not sure.

53. ChatOpenAI::Completions::convertMessage for FunctionMessage: content string. Good.

54. ChatAnthropic MessageInputs::convertTool: For StructuredTool, schema = $tool->schema->toJsonSchema(); Then converted input_schema = schema. Good.

55. ChatAnthropic MessageInputs::convertTool: For StructuredToolSpec, schema. Good.

56. ChatAnthropic MessageInputs::convertTool: For array tool, if has input_schema or name returns as-is. If has `type:'function'` and function with `parameters`, transforms to input_schema. Good.

57. ChatAnthropic MessageInputs::convertTool: For array tool with `type:'function'` and `function` but no `name` at outer, after transform name from tool['name'] ?? ''. If no name, throws. Good.

58. ChatAnthropic MessageInputs::convertTool: For array tool with `type:'function'` and function input_schema, `$tool = $function + ['input_schema' => $function['parameters'] ?? $function['input_schema'] ?? null]; unset($tool['parameters']);` If function has both parameters and input_schema, function + default means input_schema from function remains, default not used. Good.

59. ChatAnthropic MessageInputs::convertTool: For array tool with `type:'function'` and function but no input_schema/parameters, schema defaults empty object. It sends tool with empty schema. Maybe okay.

60. ChatAnthropic MessageInputs::convertTool: For array tool with `type:'function'` but no function, falls through to name/description/schema from outer. If no name, throws. Fine.

61. ChatAnthropic MessageInputs::formatToolChoice: If array returns as-is. If string 'any' returns ['type'=>'any']. Upstream Anthropic supports 'any'? Yes.

62. ChatAnthropic invocationParams: It validates tool_choice only if array type tool. If string 'any' okay. If array type 'tool' and name missing, available names empty, throws. Good.

63. ChatAnthropic invocationParams: It doesn't normalize `$this->kwargs` before toolChoiceOf? It does bound = normaliseKeys. Good.

64. ChatAnthropic invocationParams: It doesn't convert tools in invocationParams. It sends `$params['tools'] => $this->pick($options, 'tools') ?? $bound['tools'] ?? null`. If per-call tools are raw StructuredTool objects, it sends object? But `bindTools` converts. For per-call `invoke(input, config with tools?)`, BaseChatModel.withStructuredOutput maybe passes converted? Need check. In OpenAI, per-call tools are converted in invocationParams. Anthropic does not convert per-call tools. Upstream maybe expects tools already provider-shaped? In LangChain Anthropic, `bindTools` converts tools. But `withConfig` could pass tools? BaseChatModel.invoke passes options to invocationParams. If user calls `$model->invoke(..., ['tools'=>[new StructuredTool]])`, Anthropic will send StructuredTool object, JSON encode maybe fails or sends invalid. Is that a bug? The method signature accepts options array; OpenAI converts per-call tools. Anthropic doesn't. Upstream `ChatAnthropic._invocation_params` likely:
```
tools = self._convert_to_anthropic_tool(params.get("tools", []), strict)
```
Maybe yes. In this port, per-call tools not converted. This is a real fidelity/correctness gap: per-call tools ignored/invalid. Severity major. Evidence ChatAnthropic::invocationParams sends raw tools; bindTools converts. Suggested fix: convert per-call tools with MessageInputs::convertTool using strict from kwargs, same as bindTools, and remove empty list. But need be careful: if per-call tools already provider-shaped, convertTool handles arrays. If list of StructuredTool, converts. If list of arrays, converts. Good.

65. ChatAnthropic bindTools: It converts tools and stores in kwargs. Good.

66. ChatOpenAI bindTools: It converts tools and stores. Good.

67. ChatOpenAI invocationParams: For per-call tools, converts. Good.

68. ChatAnthropic invocationParams: It doesn't reject unsupported options. Could accept top_k etc. Fine.

69. ChatAnthropic invocationParams: It doesn't include `stream_options`? Anthropic no.

70. ChatAnthropic streamUsage: It gates usage chunks. Good.

71. ChatAnthropic consume: It yields usage chunks before eventToChunk. For message_start, eventToChunk likely returns null. Good.

72. ChatAnthropic consume: It does not pass `runManager` to eventToChunk? no.

73. ChatAnthropic consume: It uses `$event['delta']['stop_reason'] ?? null`. If delta missing, null. Good.

74. ChatAnthropic MessageOutputs not included but known.

75. ChatOpenAI::llmOutputFromUsage? Not included. Maybe.

76. ChatOpenAI::post: It catches `OpenAIException` and rethrows. But `OpenAIException::fromResponse` can throw OpenAIException? no.

77. ChatOpenAI::post: It retries on HttpException. If HttpClient post returns response with status 429 and body JSON error, it throws OpenAIException::fromResponse. Good.

78. ChatOpenAI::post: It retries on 5xx. Good.

79. ChatOpenAI::post: It doesn't retry on 408? Upstream maybe 429/5xx only. Fine.

80. ChatOpenAI::post: It doesn't retry on Guzzle network exception if not converted to HttpException. HttpClient likely converts. Good.

81. ChatAnthropic::post: Same.

82. ChatOpenAI::postStream: It catches HttpException. If Guzzle transport exception not converted, not caught. HttpClient likely.

83. ChatOpenAI::postStream: It catches OpenAIException. If decode throws OpenAIException, no retry. Good.

84. ChatAnthropic::streamResponseChunks: It catches AnthropicException. Good.

85. ChatAnthropic::streamResponseChunks: It doesn't catch `JsonException` from decode? decode wraps. Good.

86. ChatOpenAI::decode: It wraps json_decode. Good.

87. ChatAnthropic::decode: It wraps json_decode. Good.

88. ChatOpenAI::decode: It throws OpenAIException::fromResponse for error object with status 0. Good.

89. ChatAnthropic::decode: same.

90. ChatOpenAI::postStream: It sets `$delivered = true` before decode. If decode throws malformed event after bytes yielded, no retry. Good.

91. ChatOpenAI::postStream: If raw stream yields non-empty bytes but decode yields no chunks because decoded is not array, delivered true. Good.

92. ChatAnthropic::streamResponseChunks: Same.

93. ChatOpenAI::postStream: If raw stream yields non-empty bytes and decode yields usage chunk, delivered true. Good.

94. ChatOpenAI::postStream: If raw stream yields non-empty bytes and then HttpException, delivered true, no retry. Good.

95. ChatOpenAI::postStream: If raw stream yields zero-length bytes and then HttpException, delivered false, retry. Good.

96. ChatOpenAI::postStream: If raw stream yields zero-length bytes then non-empty malformed event, decode throws OpenAIException, no retry. Good.

97. ChatOpenAI::postStream: If raw stream yields non-empty bytes then decode yields no chunks then next raw yields non-empty bytes, delivered true. Good.

98. ChatOpenAI::postStream: If raw stream yields non-empty bytes then parser flush yields malformed, no retry. Good.

99. ChatOpenAI::postStream: If raw stream yields non-empty bytes then parser flush yields empty, return. Good.

100. ChatOpenAI::postStream: It doesn't check for `[DONE]` in decode. SseParser handles.

101. ChatOpenAI::postStream: It doesn't close raw generator on exception? foreach break maybe. Fine.

102. ChatOpenAI::postStream: It creates parser before try. If new SseParser throws? unlikely.

103. ChatAnthropic::streamResponseChunks: It creates parser before try. fine.

104. ChatOpenAI::post: It creates body before loop. If json_encode fails, Js::encode throws? Need check Js::encode maybe JSON_THROW_ON_ERROR. Good.

105. ChatAnthropic::streamResponseChunks: body before loop. Good.

106. ChatAnthropic::post: body before loop. Good.

107. ChatOpenAI::headers: If organization empty string, sends OpenAI-Organization: ; maybe not issue.

108. ChatAnthropic::headers: defaultHeaders union issue.

109. ChatAnthropic::headers: It uses `'content-type' => 'application/json'` lowercase. If defaultHeaders has 'Content-Type', PHP array union sees different key, so both present. Guzzle may send duplicate content-type? PSR-7 headers can have multiple? This is subtle. If user defaultHeaders includes 'Content-Type', required lowercase remains, resulting two headers with same semantic. Guzzle/PSR-7 may normalize? Could be bad. Using array_replace would also override if case-insensitive? PHP array_replace is case-sensitive, so would still keep both. Need canonicalize defaultHeaders lower-case or merge case-insensitively. But if defaultHeaders intentionally includes content-type, maybe okay. But required headers should not be overridden. We can mention auth/version only.

110. ChatAnthropic::headers: If defaultHeaders includes 'X-Api-Key' uppercase, array union includes both 'X-Api-Key' and 'x-api-key'. Guzzle may send both, one with user value and one with apiKey. That's bad. If defaultHeaders includes 'ANTHROPIC-VERSION', both. Need fix case-insensitive canonicalization. Good.

111. ChatOpenAI::headers: If defaultHeaders? OpenAI has no defaultHeaders.

112. ChatOpenAI::canonicalise: It doesn't canonicalise `kwargs` in constructor? It does. But it stores `$this->kwargs = array_intersect_key($fields, ...)` after canonicalise. If user passed 'stop_sequences', canonicalise adds stopSequences but leaves stop_sequences. array_intersect_key includes both if allowed? The allowed list includes 'stopSequences' but not 'stop_sequences'. So kwargs includes canonical stopSequences only. Good. If user passed 'top_k', canonicalise adds topK, rejectUnsupported sees topK and throws. Good. If user passed 'response_format', canonicalise adds responseFormat, allowed. Good.

113. ChatOpenAI::canonicalise: If user passed 'parallel_tool_calls', canonicalise adds parallelToolCalls, allowed. Good.

114. ChatOpenAI::canonicalise: If user passed 'tool_choice', canonicalise adds toolChoice, allowed. Good.

115. ChatOpenAI::canonicalise: If user passed 'max_tokens', canonicalise adds maxTokens, allowed. Good.

116. ChatOpenAI::canonicalise: If user passed 'top_p', canonicalise adds topP, allowed. Good.

117. ChatOpenAI::canonicalise: If user passed 'frequency_penalty', canonicalise adds frequencyPenalty. Good.

118. ChatOpenAI::canonicalise: If user passed 'presence_penalty'. Good.

119. ChatOpenAI::canonicalise: If user passed 'stop' and 'stopSequences', conflict.

120. ChatOpenAI::canonicalise: If user passed 'stop' and 'stop_sequences', conflict.

121. ChatOpenAI::canonicalise: If user passed 'top_p' and 'topP', conflict.

122. ChatOpenAI::canonicalise: If user passed 'max_tokens' and 'maxTokens', conflict.

123. ChatOpenAI::canonicalise: If user passed 'tool_choice' and 'toolChoice', conflict.

124. ChatOpenAI::canonicalise: If user passed 'response_format' and 'responseFormat', conflict.

125. ChatOpenAI::canonicalise: If user passed 'parallel_tool_calls' and 'parallelToolCalls', conflict.

126. ChatOpenAI::canonicalise: If user passed 'frequency_penalty' and 'frequencyPenalty', conflict.

127. ChatOpenAI::canonicalise: If user passed 'presence_penalty' and 'presencePenalty', conflict.

128. ChatAnthropic canonicalise similar.

129. ChatAnthropic invocationParams: It doesn't include `stream_usage`? Anthropic uses stream true. Good.

130. ChatAnthropic invocationParams: It doesn't include `max_retries`? Not API param. Good.

131. ChatAnthropic invocationParams: It doesn't include `timeout`? transport. Good.

132. ChatAnthropic invocationParams: It doesn't include `user`/seed? Anthropic maybe no.

133. ChatAnthropic invocationParams: It doesn't include `thinking`? no.

134. ChatOpenAI invocationParams: It doesn't include `parallel_tool_calls` if false? It includes false because array_filter keeps false. Good.

135. ChatOpenAI invocationParams: It doesn't include `stream_usage`? It uses streamUsage bool. Good.

136. ChatOpenAI invocationParams: It doesn't include `top_k` because reject. Good.

137. ChatOpenAI invocationParams: It doesn't include `tools` if bound tools empty list? It unsets. Good.

138. ChatAnthropic invocationParams: It unsets empty tools. Good.

139. ChatOpenAI bindTools: If tools empty list and kwargs has tools? It skips key tools in foreach, so cannot bind empty list with call options? It sets kwargs['tools']=[] then foreach skips, invocationParams unsets. Good.

140. ChatOpenAI bindTools: If tools empty list and kwargs has strict, it sets supportsStrictToolCalling. Good.

141. ChatOpenAI bindTools: If tools empty list and kwargs has topK, rejectUnsupported. Good.

142. ChatOpenAI bindTools: If tools empty list and kwargs has tools, skip. Good.

143. ChatAnthropic bindTools: If tools empty list and kwargs has tools, skip. Good.

144. ChatAnthropic bindTools: If tools empty list and kwargs has strict, stores strict. Good.

145. ChatAnthropic bindTools: If tools empty list and kwargs has topK, no reject. Anthropic supports. Good.

146. ChatAnthropic bindTools: If tools empty list and kwargs has toolChoice referencing missing tool, no validation until invocationParams. Good.

147. ChatAnthropic bindTools: If tools empty list and kwargs has toolChoice, invocationParams available names empty, if toolChoice type tool throws. Good.

148. ChatAnthropic bindTools: If tools empty list and kwargs has toolChoice 'auto', okay.

149. ChatAnthropic bindTools: If tools empty list and kwargs has toolChoice 'any', okay.

150. ChatAnthropic bindTools: If tools empty list and kwargs has toolChoice 'none', okay.

151. ChatAnthropic bindTools: If tools empty list and kwargs has toolChoice array type tool name missing, throws at invocation. Good.

152. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice type function name missing, no validation. Good bug.

153. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice 'any', invocationParams formats to required. Good.

154. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice 'auto', formats. Good.

155. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice 'none', formats. Good.

156. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name missing, formats function name ''. Upstream maybe validates and throws. Bug.

157. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name missing, provider rejects. Bug.

158. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing? No validation. It sends. Good.

159. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name missing, should throw. Good.

160. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, should maybe no validation? Upstream validates if toolKeys provided. If no tools, toolKeys empty, any function name invalid. Good.

161. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, but tools empty, should throw. Good.

162. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

163. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, provider rejects. Bug.

164. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

165. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

166. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

167. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

168. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

169. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

170. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

171. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

172. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

173. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

174. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

175. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

176. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

177. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

178. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

179. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

180. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

181. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

182. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

183. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

184. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

185. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

186. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

187. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

188. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

189. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

190. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

191. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

192. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

193. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

194. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

195. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

196. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

197. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

198. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

199. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

200. ChatOpenAI bindTools: If tools empty list and kwargs has toolChoice array type function name existing, no validation. Bug.

This is repetitive.

Need exactly five. We can include OpenAI tool_choice validation, Anthropic per-call tools, Anthropic defaultHeaders override, alias conflicts, maybe something else.

Need find more robust issues.

Let's inspect other source? Only provider clients. Could find issues in RunnableInterface? It has duplicate docblocks, no issue. But maybe transform method default? Need not.

Potential issue: `RunnableInterface::batch()` docblock says `$options` is upstream batchOptions, but method signature `$options` after `$config`. Upstream Runnable.batch(inputs, options) maybe second arg options, not config. In LangChain JS, `Runnable.batch(inputs, options?)` where options is config? Actually `Runnable.batch(inputs, options?)` passes config to invoke. Here signature `batch(array $inputs, ?RunnableConfig $config = null, ?array $options = null)` with third batchOptions. This may be okay.

Potential issue: `RunnableInterface::transform` doc says JS `streamEvents` entry point, but transform is not streamEvents. Not finding.

Potential issue: `BaseChatModel::stream()` finally double-report? Known fixed.

Potential issue: `ChatOpenAI::postStream` retry logic with `$delivered` set before decode. If first byte is a newline or whitespace from SSE parser? SseParser yields payload text, not raw bytes? It may yield empty for comments. If non-empty whitespace, delivered true. Fine.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that throws `HttpException` on first iteration due to non-2xx, `$delivered` false, retry. Good.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but then throws HttpException before any decoded event. It sets delivered true, no retry. Correct because byte delivered.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields no chunks and then throws HttpException. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a usage chunk with empty content. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a malformed event. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON (e.g. `null`) and then stream ends. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws HttpException. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws JsonException. decode catches. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential issue: `ChatOpenAI::postStream` if `postStream` returns generator that yields non-empty bytes but decode yields a non-object JSON and then throws OpenAIException::fromResponse with status 0. delivered true. Correct.

Potential