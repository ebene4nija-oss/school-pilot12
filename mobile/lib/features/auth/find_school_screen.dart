import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api/api_client.dart';
import '../../core/api/endpoints.dart';
import '../../core/providers.dart';

/// First launch. A handset has no subdomain, so the tenant has to be typed in
/// once and then travels as `X-School-Subdomain` on every request afterwards.
class FindSchoolScreen extends ConsumerStatefulWidget {
  const FindSchoolScreen({super.key});

  @override
  ConsumerState<FindSchoolScreen> createState() => _FindSchoolScreenState();
}

class _FindSchoolScreenState extends ConsumerState<FindSchoolScreen> {
  final _controller = TextEditingController();
  bool _checking = false;
  String? _error;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final code = _controller.text.trim().toLowerCase();
    if (code.isEmpty) {
      setState(() => _error = 'Enter your school code to continue.');
      return;
    }

    setState(() {
      _checking = true;
      _error = null;
    });

    // There is no "look up a school" endpoint, so the code is verified against
    // the health check with the tenant header attached: an unknown subdomain
    // makes TenantResolutionMiddleware answer 404 before anything else runs.
    // Cheap, unauthenticated, and it fails fast on a typo instead of at login.
    final api = ref.read(apiClientProvider);
    final holder = ref.read(sessionHolderProvider);
    final previous = holder.schoolCode;
    holder.schoolCode = code;

    try {
      await api.get<dynamic>(Api.health);
      await ref.read(authControllerProvider.notifier).setSchoolCode(code);
    } catch (error) {
      holder.schoolCode = previous;
      final failure = asApiException(error);
      setState(() {
        _error = failure.isOffline
            ? 'No connection. Check your data and try again.'
            : failure.isNotFound
                ? "We couldn't find a school with that code."
                : failure.message;
      });
    } finally {
      if (mounted) setState(() => _checking = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(AppSpacing.lg),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const SizedBox(height: AppSpacing.xl),
                Text(
                  'SchoolPilot',
                  textAlign: TextAlign.center,
                  style: AppText.headlineLg.copyWith(
                    color: AppColors.primaryContainer,
                  ),
                ),
                const SizedBox(height: AppSpacing.xl),
                Text('Find your school', style: AppText.headlineMd),
                const SizedBox(height: AppSpacing.sm),
                Text(
                  'Enter the short name your school gave you.',
                  style: AppText.bodyMd
                      .copyWith(color: AppColors.onSurfaceVariant),
                ),
                const SizedBox(height: AppSpacing.lg),
                TextField(
                  controller: _controller,
                  autocorrect: false,
                  enableSuggestions: false,
                  textInputAction: TextInputAction.done,
                  textCapitalization: TextCapitalization.none,
                  onSubmitted: (_) => _submit(),
                  decoration: InputDecoration(
                    labelText: 'School code',
                    hintText: 'graceland',
                    helperText: 'For example: graceland',
                    errorText: _error,
                    prefixIcon: const Icon(Icons.domain_outlined),
                  ),
                ),
                const SizedBox(height: AppSpacing.lg),
                FilledButton(
                  onPressed: _checking ? null : _submit,
                  child: _checking
                      ? const SizedBox(
                          height: 20,
                          width: 20,
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            color: AppColors.onAction,
                          ),
                        )
                      : const Text('Continue'),
                ),
                const SizedBox(height: AppSpacing.sm),
                TextButton(
                  onPressed: () => showDialog<void>(
                    context: context,
                    builder: (_) => AlertDialog(
                      title: const Text("Don't know your code?"),
                      content: const Text(
                        'It is in the invite SMS or email your school sent you, '
                        'and in the web address your school uses — the part '
                        'before .schoolpilot.ng.\n\n'
                        'Your school office can also tell you.',
                      ),
                      actions: [
                        TextButton(
                          onPressed: () => Navigator.pop(context),
                          child: const Text('Got it'),
                        ),
                      ],
                    ),
                  ),
                  child: const Text("I don't know my code"),
                ),
                const SizedBox(height: AppSpacing.xl),
                Text(
                  'SchoolPilot — for Nigerian schools',
                  textAlign: TextAlign.center,
                  style: AppText.labelSm.copyWith(color: AppColors.outline),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
