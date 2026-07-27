export class RunnerError extends Error {
  constructor(message, { code = 'runner_error', status = 500, details = {} } = {}) {
    super(message);
    this.name = 'RunnerError';
    this.code = code;
    this.status = status;
    this.details = details;
  }
}

export class ManualActionError extends RunnerError {
  constructor(message, details = {}) {
    super(message, { code: 'manual_action_required', status: 409, details });
    this.name = 'ManualActionError';
  }
}
