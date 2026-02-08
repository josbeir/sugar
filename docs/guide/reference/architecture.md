---
title: Architecture
description: Compilation pipeline and caching flow.
---

# Architecture

## Compilation Pipeline

1. Parser
2. DirectiveExtractionPass
3. DirectiveCompilationPass
4. ContextAnalysisPass
5. ComponentExpansionPass
6. CodeGenerator

## Caching Flow

```
Template Request
      ↓
Cache Lookup (by path hash)
      ↓
  Cache Hit? ──Yes──→ Load Cached Template
      ↓                      ↓
     No               Check Freshness (debug mode)
      ↓                      ↓
Compile Template        Fresh? ──No──→ Recompile
      ↓                      ↓
Track Dependencies          Yes
      ↓                      ↓
Cache Result          Return Compiled Code
      ↓                      ↓
Return Compiled Code   Execute Template
```
